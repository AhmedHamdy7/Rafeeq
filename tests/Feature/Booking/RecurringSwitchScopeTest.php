<?php

use App\Domains\Booking\Actions\OfferSeatToWaitlistAction;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Support\Facades\Storage;

/**
 * How much of a recurring commitment a switch moves (Feature 03 v5 §150, §187, §231, and its
 * required tests 5 and 6).
 *
 * > *"If passenger switches **only one date**, old seat for that date is released and may be
 * > filled. Old future dates remain unless passenger explicitly changes them."*
 * > *"If passenger switches **all future selected weekdays**, release old affected future bookings
 * > and their reserved capacity; replacement capacity is **not guaranteed** on dates not actually
 * > confirmed. UI must clearly warn."*
 *
 * 🔴 The two scopes differ in what she is giving up, and only one of them can be undone by waiting.
 * `this_date` costs her one morning. `all_future_weekdays` costs her a standing seat in somebody's
 * car and buys **one** morning in return — so most of this file is about that trade being done
 * exactly as asked, reported back, and never happening by accident.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->oldDriver = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->oldCommuteId = readyCommute($this->oldDriver, Vehicle::sole()->id);

    test()->withToken($this->oldDriver)
        ->postJson("/api/v1/commutes/{$this->oldCommuteId}/publish")->assertOk();

    $this->paxToken = verifiedPassenger('01112223344');

    /*
     * 🔴 ONE recurring request, approved into a booking per committed day — which is the real
     * shape of the thing this test is about. A loop of single-day requests is impossible anyway: a
     * passenger may hold only one open request per commute (`BOOKING_ALREADY_REQUESTED`), and a
     * recurring commitment is exactly what "all future weekdays" exists to move.
     */
    $requestId = requestSeat($this->paxToken, $this->oldCommuteId, [
        'commitment' => 'recurring',
        'requestedDaysMask' => DaysMask::weekdaysSunToThu()->value,
    ])->assertStatus(201)->json('data.id');

    $approval = test()->flushHeaders()->withToken($this->oldDriver)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")
        ->assertStatus(201)->json('data');

    expect($approval['seatedDays'])->toBeGreaterThan(3, 'The horizon should hold several mornings.');

    // Her mornings, soonest first, keyed by the day they are on.
    $this->oldBookings = Booking::query()
        ->whereIn('scheduled_trip_id', ScheduledTrip::query()
            ->where('commute_offer_id', $this->oldCommuteId)->select('id'))
        ->join('scheduled_trips', 'scheduled_trips.id', '=', 'bookings.scheduled_trip_id')
        ->orderBy('scheduled_trips.trip_date')
        ->pluck('bookings.id', 'bookings.scheduled_trip_id')
        ->all();

    $this->days = ScheduledTrip::query()
        ->whereIn('id', array_keys($this->oldBookings))
        ->with('commuteOffer')
        ->orderBy('trip_date')
        ->get();

    // A different driver, with one day she can be offered.
    $this->newDriver = approvedDriver(phone: '01055556666', devicePublicId: 'driver-2', seed: 2);

    $newVehicle = Vehicle::query()
        ->whereNot('id', $this->days->first()->commuteOffer->vehicle_id)
        ->sole();

    $this->newCommuteId = readyCommute($this->newDriver, $newVehicle->id);

    test()->withToken($this->newDriver)
        ->postJson("/api/v1/commutes/{$this->newCommuteId}/publish")->assertOk();

    $this->newTrip = ScheduledTrip::query()
        ->where('commute_offer_id', $this->newCommuteId)
        ->orderBy('trip_date')->firstOrFail();
});

function offerHerTheNewSeat(string $paxToken, string $driverToken, string $commuteId, string $tripId): SeatOffer
{
    $requestId = requestSeat($paxToken, $commuteId, ['scheduledTripId' => $tripId])
        ->assertStatus(201)->json('data.id');

    test()->flushHeaders()->withToken($driverToken)
        ->postJson("/api/v1/driver/seat-requests/{$requestId}/waitlist")->assertOk();

    $trip = ScheduledTrip::findOrFail($tripId);
    $trip->forceFill(['seats_taken' => 0])->save();

    app(OfferSeatToWaitlistAction::class)->forTrip($trip, 1);

    return SeatOffer::query()->where('seat_request_id', $requestId)->sole();
}

/**
 * @return array<string, string>
 */
function statusesOfHerOldDays(array $bookingIds): array
{
    $out = [];

    foreach ($bookingIds as $tripId => $bookingId) {
        $out[$tripId] = Booking::query()->whereKey($bookingId)->sole()->status->value;
    }

    return $out;
}

/*
|--------------------------------------------------------------------------
| this_date — v5's required test 5
|--------------------------------------------------------------------------
*/

/**
 * 🔴 v5: *"old future dates remain unless passenger explicitly changes them."* The default scope is
 * the recoverable one, and a client that forgets to send a scope must not end her whole term.
 */
it('leaves every other morning alone by default', function () {
    $offer = offerHerTheNewSeat($this->paxToken, $this->newDriver, $this->newCommuteId, $this->newTrip->id);

    $switchingOff = $this->days->first()->id;

    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/seat-offers/{$offer->getKey()}/accept", [
            'switchFromBookingId' => $this->oldBookings[$switchingOff],
            'switchReason' => 'voluntary',
            // No switchScope at all.
        ])
        ->assertOk()
        // Nothing beyond the one day she named.
        ->assertJsonPath('meta.releasedDates', []);

    $statuses = statusesOfHerOldDays($this->oldBookings);

    expect($statuses[$switchingOff])->toBe(BookingStatus::CancelledByPassenger->value);

    foreach ($this->days->skip(1) as $kept) {
        expect($statuses[$kept->id])->toBe(BookingStatus::Confirmed->value);
    }
});

it('does the same when the scope is sent explicitly', function () {
    $offer = offerHerTheNewSeat($this->paxToken, $this->newDriver, $this->newCommuteId, $this->newTrip->id);

    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/seat-offers/{$offer->getKey()}/accept", [
            'switchFromBookingId' => $this->oldBookings[$this->days->first()->id],
            'switchScope' => 'this_date',
        ])
        ->assertOk()
        ->assertJsonPath('meta.releasedDates', []);

    expect(Booking::query()->where('status', BookingStatus::Confirmed->value)
        ->whereIn('scheduled_trip_id', $this->days->skip(1)->pluck('id'))->count())
        ->toBe($this->days->count() - 1);
});

/**
 * v5 §150: *"For per-date switches scope membership to that date."* She rejoins for the one morning
 * she lost, not for the pattern.
 */
it('rejoins the old waiting list for that one morning', function () {
    $offer = offerHerTheNewSeat($this->paxToken, $this->newDriver, $this->newCommuteId, $this->newTrip->id);

    $switchingOff = $this->days->first()->id;

    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/seat-offers/{$offer->getKey()}/accept", [
            'switchFromBookingId' => $this->oldBookings[$switchingOff],
            'joinOldWaitlist' => true,
        ])->assertOk();

    $rejoined = SeatRequest::query()
        ->where('commute_offer_id', $this->oldCommuteId)
        ->where('status', SeatRequestStatus::Waitlisted->value)
        ->sole();

    expect($rejoined->scheduled_trip_id)->toBe($switchingOff);
});

/*
|--------------------------------------------------------------------------
| all_future_weekdays — v5's required test 6
|--------------------------------------------------------------------------
*/

/**
 * 🔴 The trade, done exactly as asked: she gives up every future morning on the old commute and
 * gets the one day this offer is for. v5 §231 says replacement capacity is not guaranteed on days
 * she has not confirmed, so pretending the new commute covers the rest would be the dishonest
 * implementation.
 */
it('releases every future morning she held on the old commute', function () {
    $offer = offerHerTheNewSeat($this->paxToken, $this->newDriver, $this->newCommuteId, $this->newTrip->id);

    $switchingOff = $this->days->first()->id;

    $response = test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/seat-offers/{$offer->getKey()}/accept", [
            'switchFromBookingId' => $this->oldBookings[$switchingOff],
            'switchReason' => 'voluntary',
            'switchScope' => 'all_future_weekdays',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'CONFIRMED');

    // 🔴 The dates come back from the call that lost them, so she is not left comparing screens.
    $released = $response->json('meta.releasedDates');

    expect($released)->toHaveCount($this->days->count() - 1)
        ->and($released)->toBe($this->days->skip(1)->pluck('trip_date')
        ->map(fn ($d) => $d->toDateString())->values()->all());

    // And not one of her old mornings is still hers.
    expect(Booking::query()
        ->whereIn('id', array_values($this->oldBookings))
        ->where('status', BookingStatus::Confirmed->value)
        ->count())->toBe(0);

    // One new seat, which is all an offer can ever be.
    expect(Booking::query()->where('status', BookingStatus::Confirmed->value)->count())->toBe(1);
});

/**
 * Their seats go back to the days they came from, so each morning is honestly bookable again — and
 * somebody else may take them, which is the half of v5's sentence that costs her.
 */
it('gives the seats back to the days she left', function () {
    $before = $this->days->mapWithKeys(fn ($d) => [$d->id => $d->seats_taken]);

    $offer = offerHerTheNewSeat($this->paxToken, $this->newDriver, $this->newCommuteId, $this->newTrip->id);

    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/seat-offers/{$offer->getKey()}/accept", [
            'switchFromBookingId' => $this->oldBookings[$this->days->first()->id],
            'switchScope' => 'all_future_weekdays',
        ])->assertOk();

    foreach ($this->days as $day) {
        expect(ScheduledTrip::query()->whereKey($day->id)->sole()->seats_taken)
            ->toBe($before[$day->id] - 1);
    }
});

/**
 * 🔴 v5 §150: *"recurring scope must reflect selected future weekdays."* A membership pinned to the
 * one Tuesday she happened to switch off would never offer her the Wednesday she also just lost.
 */
it('rejoins the old waiting list for the whole pattern, not one day', function () {
    $offer = offerHerTheNewSeat($this->paxToken, $this->newDriver, $this->newCommuteId, $this->newTrip->id);

    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/seat-offers/{$offer->getKey()}/accept", [
            'switchFromBookingId' => $this->oldBookings[$this->days->first()->id],
            'switchScope' => 'all_future_weekdays',
            'joinOldWaitlist' => true,
        ])->assertOk();

    $rejoined = SeatRequest::query()
        ->where('commute_offer_id', $this->oldCommuteId)
        ->where('status', SeatRequestStatus::Waitlisted->value)
        ->sole();

    // No single day named: the shape the fan-out treats as "any day of this commute".
    expect($rejoined->scheduled_trip_id)->toBeNull();
});

/**
 * 🔴 And one membership, not one per released morning. Re-adding per day would put her on the same
 * list four times and renumber the queue around her.
 */
it('rejoins once, however many mornings it released', function () {
    $offer = offerHerTheNewSeat($this->paxToken, $this->newDriver, $this->newCommuteId, $this->newTrip->id);

    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/seat-offers/{$offer->getKey()}/accept", [
            'switchFromBookingId' => $this->oldBookings[$this->days->first()->id],
            'switchScope' => 'all_future_weekdays',
            'joinOldWaitlist' => true,
        ])->assertOk();

    expect(SeatRequest::query()
        ->where('commute_offer_id', $this->oldCommuteId)
        ->where('status', SeatRequestStatus::Waitlisted->value)
        ->count())->toBe(1);
});

/**
 * ⚠️ v5 §149: *"no guarantee old recurring seat can be reclaimed ... Old passenger may only regain
 * seat when it becomes free and receives a new offer."* Opting out means nothing is added at all.
 */
it('adds nothing when she does not ask to rejoin', function () {
    $offer = offerHerTheNewSeat($this->paxToken, $this->newDriver, $this->newCommuteId, $this->newTrip->id);

    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/seat-offers/{$offer->getKey()}/accept", [
            'switchFromBookingId' => $this->oldBookings[$this->days->first()->id],
            'switchScope' => 'all_future_weekdays',
        ])->assertOk();

    expect(SeatRequest::query()
        ->where('commute_offer_id', $this->oldCommuteId)
        ->where('status', SeatRequestStatus::Waitlisted->value)
        ->count())->toBe(0);
});

/**
 * 🔒 A day that has already happened is not hers to give up, and reaching into one would rewrite a
 * journey that took place.
 */
it('never touches a morning that is already over', function () {
    $past = $this->days->last();

    // That day ran and she travelled on it.
    Booking::query()->whereKey($this->oldBookings[$past->id])
        ->update(['status' => BookingStatus::Completed->value]);

    ScheduledTrip::query()->whereKey($past->id)->update([
        'departure_at' => now()->subDay(),
        'status' => 'completed',
    ]);

    $offer = offerHerTheNewSeat($this->paxToken, $this->newDriver, $this->newCommuteId, $this->newTrip->id);

    $released = test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/seat-offers/{$offer->getKey()}/accept", [
            'switchFromBookingId' => $this->oldBookings[$this->days->first()->id],
            'switchScope' => 'all_future_weekdays',
        ])->assertOk()->json('meta.releasedDates');

    expect($released)->not->toContain($past->trip_date->toDateString())
        ->and(Booking::query()->whereKey($this->oldBookings[$past->id])->sole()->status)
        ->toBe(BookingStatus::Completed);
});

/**
 * 🔒 Somebody else's recurring days are not hers to release — the query is scoped to her, and a
 * second passenger on the same commute keeps every morning she holds.
 */
it('releases only her own mornings', function () {
    $otherPax = verifiedPassenger('01222220007', device: 'pax-7');

    // Her own recurring commitment on the same commute, booked the same way.
    $otherRequest = requestSeat($otherPax, $this->oldCommuteId, [
        'commitment' => 'recurring',
        'requestedDaysMask' => DaysMask::weekdaysSunToThu()->value,
    ])->assertStatus(201)->json('data.id');

    test()->flushHeaders()->withToken($this->oldDriver)
        ->postJson("/api/v1/driver/seat-requests/{$otherRequest}/approve")->assertStatus(201);

    $otherBookings = Booking::query()
        ->where('seat_request_id', $otherRequest)
        ->pluck('id')->all();

    expect($otherBookings)->not->toBeEmpty();

    $offer = offerHerTheNewSeat($this->paxToken, $this->newDriver, $this->newCommuteId, $this->newTrip->id);

    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/seat-offers/{$offer->getKey()}/accept", [
            'switchFromBookingId' => $this->oldBookings[$this->days->first()->id],
            'switchScope' => 'all_future_weekdays',
        ])->assertOk();

    expect(Booking::query()->whereIn('id', $otherBookings)
        ->where('status', BookingStatus::Confirmed->value)->count())
        ->toBe(count($otherBookings));
});

/**
 * ⚠️ A driver-delay switch carries no fee on ANY of the mornings it releases. v5 §151 says the
 * exemption is about the switch, and a recurring one releases several seats — charging per released
 * day would turn one driver's lateness into four fees.
 */
it('charges her nothing on any released morning when the driver caused it', function () {
    config(['rafeeq.booking.cancellation_fee_piastres' => 2_500]);

    $offer = offerHerTheNewSeat($this->paxToken, $this->newDriver, $this->newCommuteId, $this->newTrip->id);

    test()->flushHeaders()->withToken($this->paxToken)
        ->postJson("/api/v1/seat-offers/{$offer->getKey()}/accept", [
            'switchFromBookingId' => $this->oldBookings[$this->days->first()->id],
            'switchReason' => 'driver_delay',
            'switchScope' => 'all_future_weekdays',
        ])->assertOk();

    $fees = Booking::query()->whereIn('id', array_values($this->oldBookings))
        ->pluck('cancellation_fee_piastres')->map(fn ($f) => (int) $f)->unique()->all();

    expect($fees)->toBe([0]);

    // And every one of them is recorded as the DRIVER's cancellation, not hers.
    expect(Booking::query()->whereIn('id', array_values($this->oldBookings))
        ->where('status', BookingStatus::CancelledByDriver->value)->count())
        ->toBe($this->days->count());
});
