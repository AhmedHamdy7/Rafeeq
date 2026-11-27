<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Shared\Support\Backups;
use App\Domains\Shared\Support\SystemHealth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * The SYSTEM page: a fixed list of maintenance commands, run from the dashboard, with the output
 * shown like a terminal.
 *
 * 🔒 A LIST, not a shell — and that is the whole design. A shell in a browser means whoever holds
 * a super admin session (or steals one) can read `.env`: the database password and APP_KEY, which
 * decrypts every national ID and licence number on the platform. It can also drop the database.
 * Every command below is either read-only or something a deploy already does, so the worst a
 * stolen session gets from this page is a cleared cache.
 *
 * `system.maintain` — the super admin only. Every run is written to the audit log with who ran
 * it, which command, and its exit code, so "who ran migrate at 3am" has an answer.
 *
 * What is deliberately NOT here: `db:seed`, `migrate:fresh`, `migrate:rollback`, `demo:*`. Each
 * one destroys or invents data, and production currently runs APP_ENV=staging (CLAUDE.md), so the
 * framework's own production guards would not stop them.
 */
#[Layout('components.layouts.admin')]
class System extends Component
{
    /**
     * key => [artisan command, arguments, needs a confirm click].
     *
     * @var array<string, array{0: string, 1: array<string, mixed>, 2: bool}>
     */
    public const array COMMANDS = [
        'migrate' => ['migrate', ['--force' => true], true],
        'migrate_status' => ['migrate:status', [], false],
        'optimize_clear' => ['optimize:clear', [], true],
        'optimize' => ['optimize', [], false],
        'queue_restart' => ['queue:restart', [], false],
        'reverb_restart' => ['reverb:restart', [], false],
        'doctor' => ['rafeeq:doctor', [], false],
        'schedule_list' => ['schedule:list', [], false],
        'about' => ['about', [], false],
        // Reads the data, never changes it (BackupNow). Confirmed because it takes a while.
        'backup' => ['rafeeq:backup', [], true],
        'queue_retry_all' => ['queue:retry', ['id' => ['all']], true],
    ];

    public const array TABS = ['status', 'commands', 'errors'];

    #[Url(as: 'tab', except: 'status')]
    public string $tab = 'status';

    /** How many runs stay on screen; older ones are still in the audit log. */
    private const int HISTORY = 10;

    /** Output past this is cut, so one noisy command cannot bloat every Livewire round trip. */
    private const int MAX_OUTPUT = 20_000;

    /** @var array<int, array{key: string, command: string, exit: int, output: string, at: string}> */
    public array $runs = [];

    public function mount(): void
    {
        abort_unless($this->admin()?->can(AdminPermission::SystemMaintain->value) === true, 403);
    }

    public function run(string $key): void
    {
        // Checked on every action, not only on mount: a role can be taken away while the page is open.
        $admin = $this->admin();
        abort_unless($admin?->can(AdminPermission::SystemMaintain->value) === true, 403);
        abort_unless(array_key_exists($key, self::COMMANDS), 404);

        [$command, $arguments] = self::COMMANDS[$key];

        $this->execute($admin, $key, $command, $arguments);
    }

    /** Puts one failed job back on the queue. Only a uuid that is really in failed_jobs. */
    public function retryFailed(string $uuid): void
    {
        $admin = $this->admin();
        abort_unless($admin?->can(AdminPermission::SystemMaintain->value) === true, 403);
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);

        $this->execute($admin, 'queue_retry', 'queue:retry', ['id' => [$uuid]]);
    }

    /** Drops one failed job for good (after reading why it failed). */
    public function forgetFailed(string $uuid): void
    {
        $admin = $this->admin();
        abort_unless($admin?->can(AdminPermission::SystemMaintain->value) === true, 403);
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);

        $this->execute($admin, 'queue_forget', 'queue:forget', ['id' => $uuid]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function execute(AdminUser $admin, string $key, string $command, array $arguments): void
    {
        // A backup or a migration can outlast the default limit on a request.
        @set_time_limit(600);

        $output = new BufferedOutput;

        try {
            $exit = Artisan::call($command, $arguments + ['--no-interaction' => true], $output);
            $text = $output->fetch();
        } catch (Throwable $e) {
            $exit = 1;
            $text = $output->fetch().PHP_EOL.$e::class.': '.$e->getMessage();
        }

        $text = trim((string) preg_replace('/\e\[[\d;]*m/', '', $text));

        if (mb_strlen($text) > self::MAX_OUTPUT) {
            $text = mb_substr($text, 0, self::MAX_OUTPUT).PHP_EOL.'… '.__('admin/system.truncated');
        }

        AdminActionLog::record(
            admin: $admin,
            action: 'system.run',
            subject: $admin,
            after: ['command' => $command, 'exit_code' => $exit],
        );

        array_unshift($this->runs, [
            'key' => $key,
            'command' => self::display($command, $arguments),
            'exit' => $exit,
            'output' => $text === '' ? __('admin/system.no_output') : $text,
            'at' => now()->timezone('Africa/Cairo')->format('H:i:s'),
        ]);

        $this->runs = array_slice($this->runs, 0, self::HISTORY);
    }

    public function clearScreen(): void
    {
        $this->runs = [];
    }

    /**
     * `php artisan migrate --force`, `php artisan queue:retry all` — how a command reads on screen.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function display(string $command, array $arguments): string
    {
        $parts = ['php artisan', $command];

        foreach ($arguments as $name => $value) {
            $parts[] = str_starts_with((string) $name, '--') ? $name : implode(' ', (array) $value);
        }

        return implode(' ', $parts);
    }

    public function render()
    {
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'status';

        return view('livewire.admin.system', [
            'commands' => self::COMMANDS,
            'environment' => app()->environment(),
            'url' => config('app.url'),
            // Only what the open tab needs: the log is read from disk, the services are asked of systemd.
            'services' => $this->tab === 'status' ? SystemHealth::services() : [],
            'heartbeat' => $this->tab === 'status' ? SystemHealth::schedulerLastBeat() : null,
            'queue' => $this->tab === 'status' ? SystemHealth::queue() : null,
            'failedJobs' => $this->tab === 'status' ? SystemHealth::failedJobs() : [],
            'backups' => $this->tab === 'status' ? Backups::all() : [],
            'backupFresh' => $this->tab === 'status' && Backups::isFresh(),
            'backupEnabled' => (bool) config('rafeeq.backup.enabled'),
            // Not `errors`: that name is the validation error bag in every Blade view.
            'logEntries' => $this->tab === 'errors' ? SystemHealth::recentErrors() : [],
            'disk' => $this->tab === 'status' ? ['free' => @disk_free_space(base_path()) ?: 0, 'total' => @disk_total_space(base_path()) ?: 0] : null,
        ])->title(__('admin/system.title'));
    }

    private function admin(): ?AdminUser
    {
        $admin = Auth::guard('admin')->user();

        return $admin instanceof AdminUser ? $admin : null;
    }
}
