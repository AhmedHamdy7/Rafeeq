<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * `php artisan rafeeq:doctor` — why is this instance returning 500?
 *
 * 🔴 Built because diagnosing a hosted deploy was costing one redeploy per question. `APP_DEBUG` is
 * false on anything reachable (it must be: the debug page prints SQL, environment values and
 * source), so a 500 arrives as a blank page and the only way to learn anything was to read platform
 * logs that scroll. This asks every question at once, from inside the container, and prints the
 * answers.
 *
 * 🔒 A console command rather than a route on purpose. Everything below — whether migrations have
 * run, which tables exist, whether the documents disk is writable — is useful to an operator and
 * useful to an attacker, and a health endpoint that reports it is reporting it to everybody. The
 * platform's console is already authenticated.
 *
 * It reports; it changes nothing.
 */
final class Doctor extends Command
{
    protected $signature = 'rafeeq:doctor';

    protected $description = 'Check the things that make a deployed instance return 500.';

    /** Exit non-zero if anything is actually broken, so a release step can gate on it. */
    private bool $broken = false;

    public function handle(): int
    {
        $this->line('');
        $this->line('RAFEEQ — deployment check');
        $this->line('');

        $this->checkEnvironment();
        $this->checkDatabase();
        $this->checkMigrations();
        $this->checkDriverTables();
        $this->checkStorage();
        $this->checkBroadcasting();

        $this->line('');

        if ($this->broken) {
            $this->error('Something above is broken. Fix the ✗ lines, then run this again.');

            return self::FAILURE;
        }

        $this->info('No faults found. If requests still fail, the error is in the application — read the platform logs.');

        return self::SUCCESS;
    }

    private function checkEnvironment(): void
    {
        $this->heading('Environment');

        $this->report('APP_KEY set', config('app.key') !== null && config('app.key') !== '');
        $this->report('APP_URL has a scheme', str_starts_with((string) config('app.url'), 'http'), config('app.url'));

        /*
         * 🔒 Both of these are faults on a reachable instance and completely normal on a laptop,
         * so `local` is exempt. Without the exemption this command exits non-zero on every
         * developer machine, and a check that always fails is a check nobody reads.
         */
        if (app()->environment('local')) {
            $this->line('  · APP_ENV is local, so APP_DEBUG and LOG_LEVEL are not checked');
        } else {
            // On anything reachable, the debug page prints the SQL of the failing query, the
            // environment values, and the source around the error.
            $this->report('APP_DEBUG is off', config('app.debug') === false);

            /*
             * And this one is a sign-in bypass rather than a verbosity setting: outside
             * production, `LogOtpSender` writes the OTP into the log, so anybody who can read the
             * log can sign in as anybody.
             */
            $this->report(
                'LOG_LEVEL is not debug',
                config('logging.channels.'.config('logging.default').'.level') !== 'debug',
            );
        }

        $this->line('');
    }

    private function checkDatabase(): void
    {
        $this->heading('Database');

        try {
            DB::connection()->getPdo();

            $this->report('Connected', true, DB::connection()->getDatabaseName());
        } catch (Throwable $e) {
            // The message, not the trace: it names the host and the reason, which is the whole
            // answer for a wrong service reference or a firewall.
            $this->report('Connected', false, $e->getMessage());

            $this->line('');

            return;
        }

        /*
         * The Arabic collation matters more than it looks: names and addresses are Arabic, and the
         * wrong collation makes search and sorting wrong in ways nobody traces back to here.
         */
        try {
            $collation = DB::selectOne('SELECT @@collation_database AS c')->c ?? '';

            $this->report(
                'Collation is utf8mb4',
                str_starts_with($collation, 'utf8mb4'),
                $collation,
            );
        } catch (Throwable) {
            // Not fatal, and not worth a fault: a managed database may refuse the variable.
        }

        $this->line('');
    }

    private function checkMigrations(): void
    {
        $this->heading('Migrations');

        try {
            if (! Schema::hasTable('migrations')) {
                $this->report('Have been run', false, 'no `migrations` table — run: php artisan migrate --force');

                $this->line('');

                return;
            }

            /*
             * The real migrator, not a file count against a row count. Comparing the two would
             * report a phantom problem the first time a migration is squashed or removed — and a
             * diagnostic that cries wolf is worse than none, because the next real fault gets
             * ignored.
             */
            $migrator = app('migrator');

            $migrator->setConnection(config('database.default'));

            $ran = $migrator->getRepository()->getRan();

            // `getMigrationFiles()` is keyed by migration NAME, which is exactly what the
            // repository stores — so the difference is the pending set, with no guessing from
            // counts. (`pendingMigrations()` itself is protected.)
            $files = $migrator->getMigrationFiles(
                $migrator->paths() ?: [database_path('migrations')]
            );

            $pending = array_diff(array_keys($files), $ran);

            $this->report(
                'None pending',
                $pending === [],
                $pending === []
                    ? count($ran).' applied'
                    : count($pending).' not applied — run: php artisan migrate --force',
            );
        } catch (Throwable $e) {
            $this->report('Have been run', false, $e->getMessage());
        }

        $this->line('');
    }

    /**
     * 🔴 The most common cause of "boots fine, every request 500s".
     *
     * `SESSION_DRIVER=database` and `CACHE_STORE=database` mean the first request writes to tables
     * that only exist after a migration — so an unmigrated instance fails on every request while
     * the container itself looks perfectly healthy.
     */
    private function checkDriverTables(): void
    {
        $this->heading('Tables the configured drivers need');

        $needed = [
            'sessions' => config('session.driver') === 'database',
            'cache' => config('cache.default') === 'database',
            'jobs' => config('queue.default') === 'database',
            'users' => true,
        ];

        foreach ($needed as $table => $required) {
            if (! $required) {
                continue;
            }

            try {
                $this->report("`{$table}` exists", Schema::hasTable($table));
            } catch (Throwable $e) {
                $this->report("`{$table}` exists", false, $e->getMessage());
            }
        }

        $this->line('');
    }

    private function checkStorage(): void
    {
        $this->heading('Storage');

        $this->report('storage/ is writable', is_writable(storage_path()), storage_path());
        $this->report('bootstrap/cache is writable', is_writable(base_path('bootstrap/cache')));

        $disk = config('rafeeq.verification.documents_disk');

        try {
            $probe = 'doctor/'.bin2hex(random_bytes(8));

            Storage::disk($disk)->put($probe, 'ok');
            $readable = Storage::disk($disk)->get($probe) === 'ok';
            Storage::disk($disk)->delete($probe);

            $this->report("documents disk `{$disk}` can be written and read", $readable);
        } catch (Throwable $e) {
            $this->report("documents disk `{$disk}` can be written and read", false, $e->getMessage());
        }

        /*
         * ⚠️ A note, not a fault, because it is not broken — it is temporary. On a platform with an
         * ephemeral filesystem, a local documents disk loses every identity document on each
         * deploy, and the rows keep pointing at files that are gone. Nobody notices until a
         * reviewer opens a national ID and finds nothing.
         */
        if ($disk === 'documents' || $disk === 'local') {
            $this->warn('  ! Documents are on a LOCAL disk. If this platform has an ephemeral filesystem,');
            $this->warn('    every deploy destroys the identity documents people uploaded. Mount a volume');
            $this->warn('    or set RAFEEQ_DOCUMENTS_DISK=s3 before any real user.');
        }

        $this->line('');
    }

    private function checkBroadcasting(): void
    {
        $this->heading('Broadcasting');

        $connection = config('broadcasting.default');

        $this->line("  · connection: {$connection}");

        if ($connection !== 'reverb') {
            $this->line('    (the live map will not move by itself; position polling still works)');

            $this->line('');

            return;
        }

        /*
         * 🔴 The failure this exists to pre-empt. An empty key does not degrade — Pusher's
         * constructor type-errors on null, which is thrown while `routes/channels.php` loads, which
         * happens during `route:cache`. The container dies at boot and the platform says only
         * "Application failed to respond".
         */
        foreach (['key', 'secret', 'app_id'] as $part) {
            $this->report(
                "reverb {$part} set",
                ! in_array(config("broadcasting.connections.reverb.{$part}"), [null, ''], true),
            );
        }

        $this->line('');
    }

    private function heading(string $text): void
    {
        $this->line("<options=bold>{$text}</>");
    }

    private function report(string $label, bool $ok, ?string $detail = null): void
    {
        if (! $ok) {
            $this->broken = true;
        }

        $mark = $ok ? '<fg=green>✓</>' : '<fg=red>✗</>';

        $this->line("  {$mark} {$label}".($detail !== null && $detail !== '' ? " <fg=gray>— {$detail}</>" : ''));
    }
}
