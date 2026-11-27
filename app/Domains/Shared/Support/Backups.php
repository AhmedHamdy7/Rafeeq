<?php

namespace App\Domains\Shared\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * Reads the backup folder that `rafeeq:backup` writes: what exists, how big, and whether the
 * newest one is recent. The System page draws from this, so it reads the disk rather than a
 * cache — a cleared cache must not make a good backup look missing.
 */
final class Backups
{
    public const string FOLDER_PATTERN = '/^\d{4}-\d{2}-\d{2}_\d{6}$/';

    public static function path(): string
    {
        return (string) config('rafeeq.backup.path');
    }

    /**
     * Newest first.
     *
     * @return array<int, array{name: string, at: ?Carbon, ok: bool, database_bytes: int, files_bytes: int, error: ?string}>
     */
    public static function all(): array
    {
        if (! is_dir(self::path())) {
            return [];
        }

        $runs = [];

        foreach (File::directories(self::path()) as $dir) {
            $name = basename($dir);

            if (preg_match(self::FOLDER_PATTERN, $name) !== 1) {
                continue;
            }

            $manifest = is_file($dir.'/manifest.json')
                ? (array) json_decode((string) file_get_contents($dir.'/manifest.json'), true)
                : [];

            $runs[] = [
                'name' => $name,
                'at' => isset($manifest['finished_at']) ? Carbon::parse($manifest['finished_at']) : null,
                'ok' => ($manifest['ok'] ?? false) === true,
                'database_bytes' => is_file($dir.'/database.sql.gz') ? (int) filesize($dir.'/database.sql.gz') : 0,
                'files_bytes' => is_file($dir.'/files.tar.gz') ? (int) filesize($dir.'/files.tar.gz') : 0,
                'error' => $manifest['error'] ?? null,
            ];
        }

        usort($runs, fn (array $a, array $b) => strcmp($b['name'], $a['name']));

        return $runs;
    }

    /** The newest backup that finished cleanly, or null. */
    public static function lastGood(): ?array
    {
        foreach (self::all() as $run) {
            if ($run['ok']) {
                return $run;
            }
        }

        return null;
    }

    /** A good backup within the last 26 hours (a night's run plus slack). */
    public static function isFresh(): bool
    {
        $last = self::lastGood();

        return $last !== null && $last['at'] !== null && $last['at']->gt(now()->subHours(26));
    }
}
