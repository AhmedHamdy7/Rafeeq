<?php

use App\Domains\Analytics\Models\AnalyticsEvent;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\Schema;

it('keeps analytics rows when the user is soft-deleted (SET NULL only fires on real delete)', function () {
    $user = User::factory()->create();
    AnalyticsEvent::factory()->create(['user_id' => $user->id]);

    $user->delete(); // soft delete — the FK constraint isn't even triggered

    expect(AnalyticsEvent::where('user_id', $user->id)->exists())->toBeTrue();
});

it('has no updated_at column — analytics rows are never revised', function () {
    expect(Schema::hasColumn('analytics_events', 'updated_at'))->toBeFalse();
});
