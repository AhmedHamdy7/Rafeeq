<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\AdminActionLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
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
    ];

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
            'command' => 'php artisan '.$command.($arguments === [] ? '' : ' '.implode(' ', array_keys($arguments))),
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

    public function render()
    {
        return view('livewire.admin.system', [
            'commands' => self::COMMANDS,
            'environment' => app()->environment(),
            'url' => config('app.url'),
        ])->title(__('admin/system.title'));
    }

    private function admin(): ?AdminUser
    {
        $admin = Auth::guard('admin')->user();

        return $admin instanceof AdminUser ? $admin : null;
    }
}
