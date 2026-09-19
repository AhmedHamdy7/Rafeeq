<?php

use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteRule;
use App\Domains\Commute\Models\CommuteSchedule;

it('creates an offer with its driver and vehicle sharing the same driver profile', function () {
    $offer = CommuteOffer::factory()->create();

    expect($offer->driverProfile->user_id)->toBe($offer->vehicle->driver_profile_id);
});

it('reports women-only audience correctly', function () {
    $womenOnly = CommuteOffer::factory()->create(['audience' => CommuteAudience::WomenOnly]);
    $mixed = CommuteOffer::factory()->anyVerified()->create();

    expect($womenOnly->acceptsWomenOnly())->toBeTrue()
        ->and($mixed->acceptsWomenOnly())->toBeFalse();
});

it('follows the documented offer status state machine', function () {
    expect(CommuteOfferStatus::Draft->canTransitionTo(CommuteOfferStatus::Published))->toBeTrue()
        ->and(CommuteOfferStatus::Draft->canTransitionTo(CommuteOfferStatus::Archived))->toBeFalse()
        ->and(CommuteOfferStatus::Published->canTransitionTo(CommuteOfferStatus::Paused))->toBeTrue()
        ->and(CommuteOfferStatus::Archived->canTransitionTo(CommuteOfferStatus::Published))->toBeFalse();
});

it('loads its locations, schedule and rules', function () {
    $offer = CommuteOffer::factory()
        ->has(CommuteLocation::factory()->count(2), 'locations')
        ->has(
            CommuteRule::factory()
                ->count(2)
                ->sequence(['rule_key' => 'nonsmoking'], ['rule_key' => 'quiet']),
            'rules',
        )
        ->create();

    CommuteSchedule::factory()->for($offer, 'commuteOffer')->create();

    expect($offer->locations)->toHaveCount(2)
        ->and($offer->rules)->toHaveCount(2)
        ->and($offer->schedule)->not->toBeNull();
});
