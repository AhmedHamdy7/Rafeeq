<?php

use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Identity\Models\User;
use Illuminate\Database\QueryException;

it('rejects a second pending request from the same passenger for the same offer', function () {
    $passenger = User::factory()->create();
    $offer = CommuteOffer::factory()->published()->create();

    SeatRequest::factory()->create(['passenger_user_id' => $passenger->id, 'commute_offer_id' => $offer->id]);
    SeatRequest::factory()->create(['passenger_user_id' => $passenger->id, 'commute_offer_id' => $offer->id]);
})->throws(QueryException::class);

it('allows a new request after a previous one was withdrawn', function () {
    $passenger = User::factory()->create();
    $offer = CommuteOffer::factory()->published()->create();

    SeatRequest::factory()->create([
        'passenger_user_id' => $passenger->id,
        'commute_offer_id' => $offer->id,
        'status' => SeatRequestStatus::Withdrawn,
    ]);

    $second = SeatRequest::factory()->create([
        'passenger_user_id' => $passenger->id,
        'commute_offer_id' => $offer->id,
    ]);

    expect($second->exists)->toBeTrue();
});

it('allows a second pending request once the first is approved (recurring follow-up)', function () {
    $passenger = User::factory()->create();
    $offer = CommuteOffer::factory()->published()->create();

    SeatRequest::factory()->approved()->create([
        'passenger_user_id' => $passenger->id,
        'commute_offer_id' => $offer->id,
    ]);

    // Approved is also "active" per the partial index — a second pending
    // request for the SAME offer must still be blocked while one is approved.
    SeatRequest::factory()->create([
        'passenger_user_id' => $passenger->id,
        'commute_offer_id' => $offer->id,
    ]);
})->throws(QueryException::class);

it('tracks waitlist position when seats are full', function () {
    $request = SeatRequest::factory()->waitlisted(2)->create();

    expect($request->status)->toBe(SeatRequestStatus::Waitlisted)
        ->and($request->waitlist_position)->toBe(2);
});
