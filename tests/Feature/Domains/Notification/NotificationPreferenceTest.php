<?php

use App\Domains\Notification\Enums\NotificationCategory;
use App\Domains\Notification\Models\NotificationPreference;
use Illuminate\Database\QueryException;

it('rejects a duplicate preference row for the same user, category and channel', function () {
    $preference = NotificationPreference::factory()->create();

    NotificationPreference::factory()->create([
        'user_id' => $preference->user_id,
        'category' => $preference->category,
        'channel' => $preference->channel,
    ]);
})->throws(QueryException::class);

it('flags the safety category as never disableable', function () {
    $safety = NotificationPreference::factory()->create(['category' => NotificationCategory::Safety]);
    $marketing = NotificationPreference::factory()->create();

    expect($safety->canBeDisabled())->toBeFalse()
        ->and($marketing->canBeDisabled())->toBeTrue();
});
