<?php

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\BookingEvent;
use Illuminate\Database\QueryException;

it('rejects a second booking for the same passenger on the same trip', function () {
    $booking = Booking::factory()->create();

    Booking::factory()->create([
        'scheduled_trip_id' => $booking->scheduled_trip_id,
        'passenger_user_id' => $booking->passenger_user_id,
    ]);
})->throws(QueryException::class);

it('keeps the frozen price split exactly consistent', function () {
    $booking = Booking::factory()->create();

    expect($booking->price_snapshot_piastres)
        ->toBe($booking->platform_fee_snapshot_piastres + $booking->driver_amount_snapshot_piastres);
});

it('rejects a booking whose frozen split does not add up', function () {
    Booking::factory()->create([
        'price_snapshot_piastres' => 8800,
        'platform_fee_snapshot_piastres' => 240,
        'driver_amount_snapshot_piastres' => 8000, // off by 560
    ]);
})->throws(QueryException::class);

it('follows the documented booking status state machine', function () {
    expect(BookingStatus::Pending->canTransitionTo(BookingStatus::Confirmed))->toBeTrue()
        ->and(BookingStatus::Pending->canTransitionTo(BookingStatus::Completed))->toBeFalse()
        ->and(BookingStatus::Confirmed->canTransitionTo(BookingStatus::Completed))->toBeTrue()
        ->and(BookingStatus::Completed->canTransitionTo(BookingStatus::Confirmed))->toBeFalse()
        ->and(BookingStatus::Completed->isTerminal())->toBeTrue()
        ->and(BookingStatus::Pending->isTerminal())->toBeFalse();
});

it('logs events without allowing them to be altered', function () {
    $event = BookingEvent::factory()->create();

    expect($event->booking)->not->toBeNull();

    $event->update(['to_status' => 'tampered']);
})->throws(RuntimeException::class);
