<?php

use App\Domains\Admin\Models\AdminAction;
use App\Domains\Identity\Models\OtpChallenge;
use App\Domains\Identity\Models\SecurityEvent;
use App\Domains\Notification\Models\Message;
use App\Domains\Notification\Models\Notification;
use App\Domains\Payment\Models\PaymentWebhook;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Retention for the rows ERD §18 gives a lifetime, enforced by the scheduled `model:prune`.
 *
 * 🔴 The trap this guards: `model:prune` with no `--model` only looks in app/Models. Every model here
 * lives under app/Domains, so a schedule entry without the list runs, finds nothing, reports success
 * — and nothing is ever deleted.
 */
dataset('retained', [
    'login codes, 7 days' => [OtpChallenge::class, fn () => now()->subDays(8), fn () => now()->subDays(6)],
    'notifications, 90 days' => [Notification::class, fn () => now()->subDays(91), fn () => now()->subDays(89)],
    'trip chat, 12 months' => [Message::class, fn () => now()->subMonths(12)->subDay(), fn () => now()->subMonths(11)],
    'payment webhooks, 90 days' => [PaymentWebhook::class, fn () => now()->subDays(91), fn () => now()->subDays(89)],
    'security log, 24 months' => [SecurityEvent::class, fn () => now()->subMonths(24)->subDay(), fn () => now()->subMonths(23)],
    'admin audit log, 24 months' => [AdminAction::class, fn () => now()->subMonths(24)->subDay(), fn () => now()->subMonths(23)],
]);

it('deletes rows past their lifetime and keeps the rest', function (string $model, Closure $old, Closure $recent) {
    /** @var class-string<Model> $model */
    $expired = $model::factory()->create();
    $expired->forceFill(['created_at' => $old()])->saveQuietly();

    $kept = $model::factory()->create();
    $kept->forceFill(['created_at' => $recent()])->saveQuietly();

    $this->artisan('model:prune', ['--model' => [$model]])->assertSuccessful();

    $query = in_array(SoftDeletes::class, class_uses_recursive($model), true)
        ? $model::withTrashed()
        : $model::query();

    expect((clone $query)->whereKey($expired->getKey())->exists())->toBeFalse()
        ->and((clone $query)->whereKey($kept->getKey())->exists())->toBeTrue();
})->with('retained');

it('names every retained model in the schedule', function (string $model) {
    $commands = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command);

    $prune = $commands->first(fn (string $command) => str_contains($command, 'model:prune'));

    expect($prune)->not->toBeNull()
        ->and($prune)->toContain($model);
})->with([OtpChallenge::class, Notification::class, Message::class, PaymentWebhook::class, SecurityEvent::class, AdminAction::class]);
