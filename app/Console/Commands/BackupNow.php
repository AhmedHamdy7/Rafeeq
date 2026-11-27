<?php

namespace App\Console\Commands;

use App\Domains\Shared\Support\Backups;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * The nightly backup: the database (mysqldump → gzip) and everything under storage/app
 * (identity documents, report evidence) into a dated folder, then old folders removed.
 *
 * Infrastructure only — it reads the data, it never changes it. Scheduled at 23:55 Cairo in
 * routes/console.php, and runnable by hand from the System page or `php artisan rafeeq:backup`.
 *
 * 🔒 The database password reaches mysqldump through MYSQL_PWD in its environment, never on
 * the command line, where `ps` would show it to every user on the server.
 *
 * A failed run still writes its folder and a manifest with `ok: false` and the reason, so the
 * System page shows "the last backup failed" rather than quietly showing yesterday's.
 */
final class BackupNow extends Command
{
    protected $signature = 'rafeeq:backup';

    protected $description = 'Back up the database and uploaded files into storage/backups, keeping the last 7 days.';

    public function handle(): int
    {
        $root = Backups::path();
        $dir = $root.'/'.now('Africa/Cairo')->format('Y-m-d_His');

        File::ensureDirectoryExists($dir, 0700);
        @chmod($root, 0700);

        $manifest = ['started_at' => now()->toIso8601String(), 'ok' => false];

        try {
            $this->dumpDatabase($dir.'/database.sql.gz');
            $this->archiveFiles($dir.'/files.tar.gz');

            $manifest['ok'] = true;
        } catch (Throwable $e) {
            $manifest['error'] = mb_substr($e->getMessage(), 0, 500);
            $this->error('Backup failed: '.$manifest['error']);
        }

        $manifest['finished_at'] = now()->toIso8601String();
        $manifest['database_bytes'] = is_file($dir.'/database.sql.gz') ? filesize($dir.'/database.sql.gz') : 0;
        $manifest['files_bytes'] = is_file($dir.'/files.tar.gz') ? filesize($dir.'/files.tar.gz') : 0;

        File::put($dir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

        $removed = $this->removeOld();

        if (! $manifest['ok']) {
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Backup written to %s (database %s, files %s). Removed %d old backup(s).',
            basename($dir),
            self::size($manifest['database_bytes']),
            self::size($manifest['files_bytes']),
            $removed,
        ));

        return self::SUCCESS;
    }

    private function dumpDatabase(string $target): void
    {
        $connection = config('database.default');
        $db = config("database.connections.{$connection}");

        if (($db['driver'] ?? null) !== 'mysql') {
            throw new \RuntimeException("Only MySQL is backed up (this connection is {$db['driver']}).");
        }

        $command = sprintf(
            'set -o pipefail; mysqldump --single-transaction --quick --no-tablespaces --routines --triggers --host=%s --port=%s --user=%s %s | gzip -6 > %s',
            escapeshellarg((string) $db['host']),
            escapeshellarg((string) $db['port']),
            escapeshellarg((string) $db['username']),
            escapeshellarg((string) $db['database']),
            escapeshellarg($target),
        );

        $result = Process::env(['MYSQL_PWD' => (string) $db['password']])
            ->timeout(1800)
            ->run(['bash', '-c', $command]);

        if (! $result->successful()) {
            throw new \RuntimeException('mysqldump: '.trim($result->errorOutput() ?: $result->output()));
        }

        // A truncated gzip is the classic silent failure: the file exists and is useless.
        $check = Process::run(['gzip', '-t', $target]);

        if (! $check->successful()) {
            throw new \RuntimeException('The database archive did not verify: '.trim($check->errorOutput()));
        }
    }

    private function archiveFiles(string $target): void
    {
        $result = Process::timeout(1800)->run(['tar', '-czf', $target, '-C', storage_path(), 'app']);

        if (! $result->successful()) {
            throw new \RuntimeException('tar: '.trim($result->errorOutput()));
        }
    }

    private function removeOld(): int
    {
        $keep = max(1, (int) config('rafeeq.backup.keep_days', 7));
        $cutoff = now('Africa/Cairo')->subDays($keep)->format('Y-m-d_His');
        $removed = 0;

        foreach (File::directories(Backups::path()) as $dir) {
            $name = basename($dir);

            // Only folders this command made, and only by their own date — never anything else in there.
            if (preg_match(Backups::FOLDER_PATTERN, $name) === 1 && strcmp($name, $cutoff) < 0) {
                File::deleteDirectory($dir);
                $removed++;
            }
        }

        return $removed;
    }

    public static function size(int $bytes): string
    {
        return match (true) {
            $bytes >= 1_073_741_824 => round($bytes / 1_073_741_824, 2).' GB',
            $bytes >= 1_048_576 => round($bytes / 1_048_576, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024).' KB',
            default => $bytes.' B',
        };
    }
}
