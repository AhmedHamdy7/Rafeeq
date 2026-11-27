<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Shared\Support\Backups;
use App\Domains\Shared\Support\SystemHealth;
use App\Livewire\Admin\System;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The System page's status tab, the error log and the nightly backup. All of it infrastructure:
 * none of it reads or writes a booking, a member or a setting.
 */
beforeEach(function () {
    $this->backupDir = storage_path('framework/testing/backups-'.Str::random(8));
    config(['rafeeq.backup.path' => $this->backupDir]);
});

afterEach(function () {
    File::deleteDirectory($this->backupDir);
});

function failedJobRow(string $uuid): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\SendPushNotification']),
        'exception' => "RuntimeException: FCM refused token for +201012345678\n#0 stack",
        'failed_at' => now(),
    ]);
}

// ---- The backup ---------------------------------------------------------------------------

it('writes a dated backup with a manifest and removes backups older than a week', function () {
    Process::fake();

    $old = $this->backupDir.'/'.now('Africa/Cairo')->subDays(10)->format('Y-m-d_His');
    $recent = $this->backupDir.'/'.now('Africa/Cairo')->subDays(2)->format('Y-m-d_His');
    File::ensureDirectoryExists($old);
    File::ensureDirectoryExists($recent);
    // Never touched, whatever its age: not a folder this command made.
    File::ensureDirectoryExists($this->backupDir.'/keep-me');

    $this->artisan('rafeeq:backup')->assertSuccessful();

    $runs = Backups::all();

    expect($runs)->toHaveCount(2)
        ->and($runs[0]['ok'])->toBeTrue()
        ->and(is_dir($old))->toBeFalse()
        ->and(is_dir($recent))->toBeTrue()
        ->and(is_dir($this->backupDir.'/keep-me'))->toBeTrue()
        ->and(Backups::isFresh())->toBeTrue();

    // 🔒 The password goes through the environment, never on the command line.
    Process::assertRan(fn ($process) => is_array($process->command)
        && str_contains(implode(' ', $process->command), 'mysqldump')
        && ! str_contains(implode(' ', $process->command), (string) config('database.connections.mysql.password') ?: '\0never'));
});

it('records a failed backup instead of pretending', function () {
    Process::fake(['*' => Process::result(errorOutput: 'Access denied', exitCode: 2)]);

    $this->artisan('rafeeq:backup')->assertFailed();

    $runs = Backups::all();

    expect($runs)->toHaveCount(1)
        ->and($runs[0]['ok'])->toBeFalse()
        ->and($runs[0]['error'])->toContain('Access denied')
        ->and(Backups::isFresh())->toBeFalse();
});

it('schedules the backup nightly', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'rafeeq:backup'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('55 23 * * *')
        ->and($event->timezone)->toBe('Africa/Cairo');
});

it('skips the nightly backup where it is switched off, and says so on the page', function () {
    Process::fake();
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'rafeeq:backup'));

    config(['rafeeq.backup.enabled' => true]);
    expect($event->filtersPass(app()))->toBeTrue();

    config(['rafeeq.backup.enabled' => false]);
    expect($event->filtersPass(app()))->toBeFalse();

    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));
    Livewire::test(System::class)->assertSee(__('admin/system.backups.disabled'));
});

// ---- Redaction ----------------------------------------------------------------------------

it('hides phone numbers, national IDs, emails and secrets from what the page shows', function () {
    $text = SystemHealth::redact('user +201012345678 / 01012345678 id 29604120100001 mail salma@example.com password=hunter2 Bearer abc.def.ghi');

    expect($text)
        ->not->toContain('1012345678')
        ->not->toContain('29604120100001')
        ->not->toContain('salma@example.com')
        ->not->toContain('hunter2')
        ->not->toContain('abc.def.ghi');
});

// ---- The page -----------------------------------------------------------------------------

it('opens on the status tab with the queue, failed jobs and backups', function () {
    // `*systemctl*`: an array command is matched in its quoted form ('systemctl' 'is-active' …).
    Process::fake(['*systemctl*' => Process::result(output: 'active')]);
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));
    failedJobRow('11111111-1111-1111-1111-111111111111');

    Livewire::test(System::class)
        ->assertViewHas('services', fn (array $services) => $services['queue'] === 'running' && $services['reverb'] === 'running')
        ->assertViewHas('queue', fn (array $queue) => $queue['failed'] === 1)
        ->assertSee('SendPushNotification')
        // 🔒 The failure reason is shown, the phone number in it is not.
        ->assertDontSee('+201012345678')
        ->assertSee(__('admin/system.backups.none'));
});

it('retries and deletes a failed job, and audits both', function () {
    Process::fake();
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));
    failedJobRow('22222222-2222-2222-2222-222222222222');

    Artisan::shouldReceive('call')->once()->with('queue:retry', Mockery::on(fn ($a) => $a['id'] === ['22222222-2222-2222-2222-222222222222']), Mockery::any())->andReturn(0);
    Artisan::shouldReceive('call')->once()->with('queue:forget', Mockery::on(fn ($a) => $a['id'] === '22222222-2222-2222-2222-222222222222'), Mockery::any())->andReturn(0);

    Livewire::test(System::class)
        ->call('retryFailed', '22222222-2222-2222-2222-222222222222')
        ->assertSee('php artisan queue:retry 22222222-2222-2222-2222-222222222222')
        ->call('forgetFailed', '22222222-2222-2222-2222-222222222222');

    expect(AdminAction::query()->where('action', 'system.run')->count())->toBe(2);
});

it('refuses to retry a job that is not in the failed list', function () {
    Process::fake();
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    Livewire::test(System::class)
        ->call('retryFailed', '33333333-3333-3333-3333-333333333333')
        ->assertNotFound();
});

it('shows the latest errors from the log, redacted', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    $log = storage_path('logs/system-page-test-'.Str::random(6).'.log');
    File::put($log, implode("\n", [
        '[2026-11-27 10:00:00] staging.INFO: Nothing to see here',
        '[2026-11-27 10:01:00] staging.ERROR: Push failed for +201012345678 {"exception":"x"}',
        '#0 /var/www/app.php(10): stack line',
        '[2026-11-27 10:02:00] staging.WARNING: Slow route lookup',
    ])."\n");
    touch($log, time() + 60);

    try {
        Livewire::test(System::class)
            ->set('tab', 'errors')
            ->assertSee('Push failed for')
            ->assertSee('Slow route lookup')
            ->assertDontSee('+201012345678')
            ->assertDontSee('Nothing to see here')
            ->assertDontSee('stack line');
    } finally {
        File::delete($log);
    }
});
