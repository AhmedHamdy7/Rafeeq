<?php

use App\Domains\Booking\Enums\DetourTier;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\FreeCancel;
use App\Domains\Commute\Actions\RenewOrEndCommuteAction;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\CommutePausedReason;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteRule;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Commute\Support\CommuteGuards;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Geo\ValueObjects\Route;
use App\Domains\Identity\Actions\CompleteBasicProfileAction;
use App\Domains\Identity\Enums\Gender;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Notification;
use App\Domains\Payment\Support\PassengerFees;
use App\Domains\Shared\ValueObjects\DaysMask;
use App\Domains\Shared\ValueObjects\Distance;
use App\Domains\Verification\Models\UserVerification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

function unverifiedDriver(string $phone = '01223334455'): string
{
    fakeOtpSender();
    $token = signIn(phone: $phone, devicePublicId: 'unverified-'.$phone)['session']['accessToken'];
    completeBasicProfile($token);
    test()->withToken($token)->postJson('/api/v1/account/capabilities/driver')->assertOk();

    return $token;
}

function arrivalSchedule(string $token, string $commuteId, array $body = [])
{
    return test()->withToken($token)->putJson("/api/v1/commutes/{$commuteId}/schedule", array_merge([
        'daysMask' => 62,
        'promisedArrivalTime' => '09:00:00',
    ], $body));
}

function minimalCommute(string $token, array $body = [])
{
    return test()->withToken($token)->postJson('/api/v1/commutes', $body);
}

beforeEach(function () {
    Storage::fake('documents');
});

it('lets a driver who has not started verification save a whole draft and gates only publish', function () {
    $token = unverifiedDriver();

    $draft = minimalCommute($token)->assertStatus(201);
    $id = $draft->json('data.id');

    expect($draft->json('data.vehicleId'))->toBeNull()
        ->and($draft->json('data.seatsTotal'))->toBeNull()
        ->and($draft->json('data.missing'))->toBe(['origin', 'destination', 'schedule', 'vehicle']);

    saveRoute($token, $id)->assertOk();
    arrivalSchedule($token, $id)->assertOk();

    test()->withToken($token)->patchJson("/api/v1/commutes/{$id}", ['rules' => ['nonsmoking' => true, 'music' => true]])
        ->assertOk()
        ->assertJsonPath('data.rules.music', true);

    test()->withToken($token)->getJson("/api/v1/commutes/{$id}")
        ->assertOk()
        ->assertJsonPath('data.missing', ['vehicle']);

    test()->withToken($token)->getJson('/api/v1/commutes?status=draft')
        ->assertOk()
        ->assertJsonPath('data.0.id', $id);

    test()->withToken($token)->getJson("/api/v1/commutes/{$id}/route-preview")->assertOk();

    test()->withToken($token)->getJson("/api/v1/commutes/{$id}/price")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMUTE_INCOMPLETE')
        ->assertJsonPath('error.fields.missing.0', 'vehicle');

    test()->withToken($token)->postJson("/api/v1/commutes/{$id}/publish")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'VERIFICATION_REQUIRED');
});

it('refuses a draft from an account without the driver capability', function () {
    fakeOtpSender();
    $token = signIn(phone: '01223334466', devicePublicId: 'passenger-only')['session']['accessToken'];
    completeBasicProfile($token);

    minimalCommute($token)->assertStatus(403)->assertJsonPath('error.code', 'DRIVER_NOT_ELIGIBLE');
});

it('defaults every value the form does not ask for', function () {
    $token = approvedDriver();

    minimalCommute($token)
        ->assertStatus(201)
        ->assertJsonPath('data.commuteType', 'recurring')
        ->assertJsonPath('data.direction', 'to_work')
        ->assertJsonPath('data.audience', 'any_verified')
        ->assertJsonPath('data.allowsCustomPickup', true)
        ->assertJsonPath('data.vehicleId', Vehicle::sole()->id)
        ->assertJsonPath('data.seatsTotal', 3);
});

it('keeps a car without a review on the draft, and refuses it at price and publish', function () {
    $token = approvedDriver();
    Vehicle::sole()->forceFill(['approved_seats' => null])->save();

    $id = minimalCommute($token)
        ->assertStatus(201)
        ->assertJsonPath('data.seatsTotal', null)
        ->json('data.id');

    saveRoute($token, $id)->assertOk();
    arrivalSchedule($token, $id)->assertOk();

    test()->withToken($token)->getJson("/api/v1/commutes/{$id}/price")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'VEHICLE_SEATS_NOT_APPROVED');

    test()->withToken($token)->postJson("/api/v1/commutes/{$id}/publish")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'VEHICLE_SEATS_NOT_APPROVED');
});

it('fills the seats from the car when a vehicle joins the draft', function () {
    $token = approvedDriver();
    $vehicle = Vehicle::sole();

    $vehicle->forceFill(['is_active' => false])->save();
    $id = minimalCommute($token)->assertJsonPath('data.vehicleId', null)->json('data.id');

    test()->withToken($token)->patchJson("/api/v1/commutes/{$id}", ['vehicleId' => $vehicle->id])
        ->assertOk()
        ->assertJsonPath('data.vehicleId', $vehicle->id)
        ->assertJsonPath('data.seatsTotal', 3);

    test()->withToken($token)->patchJson("/api/v1/commutes/{$id}", ['seatsTotal' => 9])->assertStatus(422);
});

it('publishes with the approved seats and defaulted dates', function () {
    $token = approvedDriver();
    $id = minimalCommute($token)->json('data.id');

    saveRoute($token, $id)->assertOk();

    $schedule = arrivalSchedule($token, $id)->assertOk()->json('data.schedule');
    $tomorrow = CarbonImmutable::tomorrow('Africa/Cairo');

    expect($schedule['startDate'])->toBe($tomorrow->toDateString())
        ->and($schedule['endDate'])->toBe($tomorrow->addMonths(12)->toDateString());

    test()->withToken($token)->postJson("/api/v1/commutes/{$id}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'PUBLISHED')
        ->assertJsonPath('data.seatsTotal', 3);
});

it('keeps saved dates when the schedule is saved again without any', function () {
    $token = approvedDriver();
    $id = minimalCommute($token)->json('data.id');
    $start = CarbonImmutable::today()->addDays(5)->toDateString();
    $end = CarbonImmutable::today()->addDays(40)->toDateString();

    arrivalSchedule($token, $id, ['startDate' => $start, 'endDate' => $end])->assertOk();

    arrivalSchedule($token, $id)
        ->assertOk()
        ->assertJsonPath('data.schedule.startDate', $start)
        ->assertJsonPath('data.schedule.endDate', $end);
});

it('takes a weekly schedule sent only through perDay, and returns each day to resume from', function () {
    $token = approvedDriver();
    $id = minimalCommute($token)->json('data.id');

    saveRoute($token, $id)->assertOk();

    $weekdays = test()->withToken($token)->putJson("/api/v1/commutes/{$id}/schedule", [
        'daysMask' => 10,
        'perDay' => [
            '2' => ['promisedArrivalTime' => '09:00:00', 'seatsTotal' => 2],
            '8' => ['promisedArrivalTime' => '10:00:00'],
        ],
    ])->assertOk()->json('data.weekdays');

    expect($weekdays)->toHaveCount(2)
        ->and(collect($weekdays)->firstWhere('weekdayBit', 2))->toMatchArray([
            'promisedArrivalTime' => '09:00:00',
            'departureIsComputed' => true,
            'seatsTotal' => 2,
        ]);
});

it('gives the road for the route step, or says it cannot be calculated', function () {
    $token = approvedDriver();
    $id = minimalCommute($token)->json('data.id');

    test()->withToken($token)->getJson("/api/v1/commutes/{$id}/route-preview")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMUTE_INCOMPLETE');

    saveRoute($token, $id)->assertOk();

    $preview = test()->withToken($token)->getJson("/api/v1/commutes/{$id}/route-preview")->assertOk()->json('data');

    expect($preview['polyline'])->not->toBe('')
        ->and($preview['distanceMeters'])->toBeGreaterThan(0)
        ->and($preview['durationSeconds'])->toBeGreaterThan(0);

    $this->mock(GeoQueryEngine::class)->shouldReceive('routeBetween')->andReturn(new Route(
        polyline: 'abc',
        distance: Distance::fromMetres(100),
        durationSeconds: 10,
        isFallback: true,
    ));

    test()->withToken($token)->getJson("/api/v1/commutes/{$id}/route-preview")
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'COMMUTE_ROUTE_UNAVAILABLE');
});

it('gives the form its limits and defaults, moved by the admin settings', function () {
    $token = unverifiedDriver();

    $options = test()->withToken($token)->getJson('/api/v1/commutes/options')->assertOk()->json('data');

    expect($options)->toMatchArray([
        'maxPickupStops' => 3,
        'maxDropoffStops' => 3,
        'maxDetourMinutes' => 30,
        'maxScheduleMonths' => 12,
        'canMarkWomenOnly' => true,
        'preferences' => ['nonsmoking', 'quiet', 'ac', 'nofood', 'music'],
    ])->and($options['defaults']['allowsCustomPickup'])->toBeTrue()
        ->and($options['defaults']['startDate'])->toBe(CarbonImmutable::tomorrow('Africa/Cairo')->toDateString());
});

it('takes one point in both lists, counted in each', function () {
    $token = approvedDriver();
    $id = minimalCommute($token)->json('data.id');
    $point = ['lat' => 30.0650, 'lng' => 31.2500, 'address' => 'Shared stop'];

    $locations = saveRoute($token, $id, ['pickups' => [$point], 'dropoffs' => [$point]])->assertOk()->json('data.locations');

    expect(collect($locations)->where('address', 'Shared stop'))->toHaveCount(2);

    saveRoute($token, $id, ['pickups' => [$point, $point, $point, $point]])->assertStatus(422);
});

it('lets a driver hold several drafts, and discards one out of the list', function () {
    $token = approvedDriver();
    $first = minimalCommute($token)->json('data.id');
    $second = minimalCommute($token)->json('data.id');

    test()->withToken($token)->getJson('/api/v1/commutes?status=draft')->assertOk()->assertJsonCount(2, 'data');

    test()->withToken($token)->deleteJson("/api/v1/commutes/{$first}")
        ->assertOk()
        ->assertJsonPath('data.status', 'ARCHIVED');

    test()->withToken($token)->getJson('/api/v1/commutes')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $second);

    test()->withToken($token)->getJson('/api/v1/commutes?status=nonsense')->assertStatus(422);
});

it('refuses an unknown preference key', function () {
    $token = approvedDriver();
    $id = minimalCommute($token)->json('data.id');

    test()->withToken($token)->patchJson("/api/v1/commutes/{$id}", ['rules' => ['smoking_room' => true]])
        ->assertStatus(422);
});

it('saves a one-time schedule without a days mask', function () {
    $token = approvedDriver();
    $id = minimalCommute($token, ['commuteType' => 'one_time'])->json('data.id');
    $date = CarbonImmutable::today()->addDays(3)->toDateString();

    test()->withToken($token)->putJson("/api/v1/commutes/{$id}/schedule", [
        'startDate' => $date,
        'promisedArrivalTime' => '09:00:00',
    ])->assertOk()
        ->assertJsonPath('data.schedule.startDate', $date)
        ->assertJsonPath('data.schedule.endDate', $date);
});

it('asks a recurring schedule for its days', function () {
    $token = approvedDriver();
    $id = minimalCommute($token)->json('data.id');

    test()->withToken($token)->putJson("/api/v1/commutes/{$id}/schedule", ['promisedArrivalTime' => '09:00:00'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMUTE_INCOMPLETE');
});

it('carries the arrival and the computed flag onto a duplicate', function () {
    $token = approvedDriver();
    $id = minimalCommute($token)->json('data.id');

    saveRoute($token, $id)->assertOk();
    arrivalSchedule($token, $id)->assertOk();

    $weekdays = test()->withToken($token)->postJson("/api/v1/commutes/{$id}/duplicate")
        ->assertStatus(201)
        ->json('data.weekdays');

    expect($weekdays[0]['departureIsComputed'])->toBeTrue();
});

it('still needs verification to change a published commute', function () {
    $token = approvedDriver();
    $id = readyCommute($token, Vehicle::sole()->id);

    test()->withToken($token)->postJson("/api/v1/commutes/{$id}/publish")->assertOk();

    UserVerification::query()->where('user_id', User::query()->sole()->id)->delete();
    User::sole()->unsetRelation('verifications');

    test()->withToken($token)->patchJson("/api/v1/commutes/{$id}", ['seatsTotal' => 2])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'VERIFICATION_REQUIRED');

    test()->withToken($token)->deleteJson("/api/v1/commutes/{$id}")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'VERIFICATION_REQUIRED');
});

it('takes no earliest departure', function () {
    $token = approvedDriver();
    $id = minimalCommute($token)->json('data.id');

    saveRoute($token, $id)->assertOk();

    $weekdays = arrivalSchedule($token, $id, ['earliestDepartureTime' => '05:00:00'])->assertOk()->json('data.weekdays');

    expect($weekdays[0])->not->toHaveKey('earliestDepartureTime');
});

it('sorts a request into the two detour tiers, and refuses beyond them', function () {
    expect(DetourTier::for(1500, 10, 20))->toBe(DetourTier::OnWay)
        ->and(DetourTier::for(1501, 10, 20))->toBe(DetourTier::Far)
        ->and(DetourTier::for(1500, 10.5, 20))->toBe(DetourTier::Far)
        ->and(DetourTier::for(1500, 10, 21))->toBe(DetourTier::Far)
        ->and(DetourTier::for(3000, 15, 30))->toBe(DetourTier::Far)
        ->and(DetourTier::for(3001, 15, 30))->toBeNull()
        ->and(DetourTier::for(3000, 15.5, 30))->toBeNull()
        ->and(DetourTier::for(3000, 15, 31))->toBeNull();
});

it('returns the detour tiers as options', function () {
    $token = approvedDriver();

    test()->withToken($token)->getJson('/api/v1/commutes/options')
        ->assertOk()
        ->assertJsonPath('data.detourTiers.onWay', ['pointMeters' => 1500, 'requestMinutes' => 10, 'tripMinutes' => 20])
        ->assertJsonPath('data.detourTiers.far', ['pointMeters' => 3000, 'requestMinutes' => 15, 'tripMinutes' => 30]);
});

it('refuses AC on a car without it, and clears the chip when the car loses it', function () {
    $token = approvedDriver();
    $vehicle = Vehicle::sole();
    $vehicle->forceFill(['has_ac' => false, 'approved_seats' => 3])->save();
    $id = minimalCommute($token)->json('data.id');

    test()->withToken($token)->patchJson("/api/v1/commutes/{$id}", ['rules' => ['ac' => true]])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMUTE_AC_NOT_AVAILABLE');

    $vehicle->forceFill(['has_ac' => true])->save();

    test()->withToken($token)->patchJson("/api/v1/commutes/{$id}", ['rules' => ['ac' => true, 'music' => true]])->assertOk();

    $vehicle->forceFill(['has_ac' => false])->save();
    CommuteGuards::clearAcOnVehicleCommutes($vehicle);

    expect(CommuteRule::query()->pluck('rule_key')->map->value->all())->toBe(['music']);
});

it('refuses Women-only from a driver who is not a woman', function () {
    $token = approvedDriver();
    User::query()->whereHas('driverProfile')->update(['gender' => Gender::Man->value]);

    test()->withToken($token)->postJson('/api/v1/commutes', ['audience' => 'women_only'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMUTE_WOMEN_ONLY_NOT_ALLOWED');

    $id = minimalCommute($token)->json('data.id');

    test()->withToken($token)->patchJson("/api/v1/commutes/{$id}", ['audience' => 'women_only'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'COMMUTE_WOMEN_ONLY_NOT_ALLOWED');
});

it('pauses a Women-only commute when the driver stops being a woman', function () {
    $token = approvedDriver();
    $user = User::query()->whereHas('driverProfile')->sole();
    $user->forceFill(['gender' => Gender::Woman->value])->save();
    $id = publishedCommute($token);
    CommuteOffer::query()->whereKey($id)->update(['audience' => 'women_only']);

    app(CompleteBasicProfileAction::class)->execute($user->fresh(), ['fullName' => 'Sam Driver', 'gender' => Gender::Man->value]);

    expect(CommuteOffer::query()->whereKey($id)->sole())
        ->status->toBe(CommuteOfferStatus::Paused)
        ->paused_reason->toBe(CommutePausedReason::GenderReview);
});

it('renews a used commute near its end date and leaves an idle one to end', function () {
    $token = approvedDriver();
    $id = publishedCommute($token);
    $offer = CommuteOffer::query()->with('schedule')->whereKey($id)->sole();
    $end = CarbonImmutable::today()->addDays(5);
    $offer->schedule->forceFill(['end_date' => $end->toDateString()])->save();

    expect(app(RenewOrEndCommuteAction::class)->renewIfDue($offer->fresh('schedule')))->toBeFalse();

    ScheduledTrip::query()->where('commute_offer_id', $id)->orderBy('trip_date')->firstOrFail()->forceFill([
        'status' => ScheduledTripStatus::Completed->value,
        'trip_date' => CarbonImmutable::today()->subDays(2)->toDateString(),
    ])->save();

    expect(app(RenewOrEndCommuteAction::class)->renewIfDue($offer->fresh('schedule')))->toBeTrue()
        ->and($offer->fresh('schedule')->schedule->end_date->toDateString())->toBe($end->addMonthsNoOverflow(12)->toDateString());
});

it('tells the driver and the waiting list when a commute ends', function () {
    $token = approvedDriver();
    $id = publishedCommute($token);
    $trip = ScheduledTrip::query()->where('commute_offer_id', $id)->orderBy('trip_date')->skip(2)->firstOrFail();
    $requestId = requestSeat(verifiedPassenger('01112223344'), $id, ['scheduledTripId' => $trip->id])->assertStatus(201)->json('data.id');
    SeatRequest::query()->whereKey($requestId)->update(['status' => SeatRequestStatus::Waitlisted->value]);

    app(RenewOrEndCommuteAction::class)->end(CommuteOffer::query()->whereKey($id)->sole());

    expect(CommuteOffer::query()->whereKey($id)->sole()->status)->toBe(CommuteOfferStatus::Archived)
        ->and(Notification::query()->where('type', 'commute_archived')->count())->toBe(1)
        ->and(Notification::query()->where('type', 'waitlisted_commute_ended')->count())->toBe(1);
});

it('lets her cancel free only when her pickup moved more than ten minutes earlier', function () {
    $token = approvedDriver();
    $id = publishedCommute($token);
    $trip = ScheduledTrip::query()->where('commute_offer_id', $id)->orderBy('trip_date')->skip(2)->firstOrFail();
    $requestId = requestSeat(verifiedPassenger('01112223344'), $id, ['scheduledTripId' => $trip->id])->assertStatus(201)->json('data.id');
    test()->withToken($token)->postJson("/api/v1/driver/seat-requests/{$requestId}/approve")->assertStatus(201);

    $booking = Booking::sole();
    $agreed = CarbonImmutable::instance($booking->agreed_pickup_at);

    expect(FreeCancel::pickupMovedEarlier($booking))->toBeFalse();

    $booking->scheduledTrip->forceFill(['departure_at' => CarbonImmutable::instance($booking->scheduledTrip->departure_at)->subMinutes(10)])->save();
    expect(FreeCancel::pickupMovedEarlier($booking->fresh()))->toBeFalse();

    $booking->scheduledTrip->forceFill(['departure_at' => CarbonImmutable::instance($booking->scheduledTrip->departure_at)->subMinutes(5)])->save();
    expect(FreeCancel::pickupMovedEarlier($booking->fresh()))->toBeTrue();

    $this->travelTo($agreed->subHours(2));

    expect(PassengerFees::cancelPreview($booking->fresh()))
        ->toMatchArray(['feePiastres' => 0, 'reason' => 'pickup_moved_earlier']);
});

it('says why a draft with an unapproved car cannot publish', function () {
    $token = approvedDriver();
    $vehicle = Vehicle::sole();
    $id = minimalCommute($token)->json('data.id');

    $vehicle->forceFill(['approved_seats' => null])->save();
    test()->withToken($token)->getJson("/api/v1/commutes/{$id}")->assertJsonFragment(['vehicle_under_review']);

    $vehicle->forceFill(['verification_status' => VehicleVerificationStatus::Rejected->value])->save();
    test()->withToken($token)->getJson("/api/v1/commutes/{$id}")->assertJsonFragment(['vehicle_rejected']);
});

it('returns why a car was rejected', function () {
    $token = approvedDriver();

    Vehicle::sole()->forceFill([
        'verification_status' => VehicleVerificationStatus::Rejected->value,
        'rejection_reason' => 'The registration photo is unreadable.',
    ])->save();

    test()->withToken($token)->getJson('/api/v1/driver/vehicles')
        ->assertOk()
        ->assertJsonPath('data.0.rejectionReason', 'The registration photo is unreadable.');
});

it('leaves no placeholder in the licence refusal', function (string $locale) {
    app()->setLocale($locale);

    expect(__('errors.DRIVER_LICENCE_EXPIRED'))->not->toContain(':days')
        ->and(__('errors.DRIVER_LICENCE_MINIMUM_VALIDITY', ['days' => 30]))->toContain('30');
})->with(['en', 'ar']);

it('fills weekday rows for a commute that has none, and leaves the others alone', function () {
    $token = approvedDriver();
    $id = publishedCommute($token);
    CommuteOffer::query()->whereKey($id)->sole()->weekdaySchedules()->delete();
    $other = minimalCommute($token)->json('data.id');
    saveRoute($token, $other)->assertOk();
    arrivalSchedule($token, $other)->assertOk();
    $before = CommuteOffer::query()->whereKey($other)->sole()->weekdaySchedules()->pluck('id')->sort()->values()->all();

    (require database_path('migrations/2026_11_05_110000_backfill_commute_weekday_rows.php'))->up();

    $offer = CommuteOffer::query()->with('schedule')->whereKey($id)->sole();

    expect($offer->weekdaySchedules()->pluck('weekday_bit')->sort()->values()->all())->toBe(DaysMask::fromBits((int) $offer->schedule->days_mask)->bits())
        ->and(CommuteOffer::query()->whereKey($other)->sole()->weekdaySchedules()->pluck('id')->sort()->values()->all())->toBe($before);
});

it('tells a suspended driver why, not to apply', function () {
    $token = approvedDriver();
    $id = minimalCommute($token)->json('data.id');
    saveRoute($token, $id)->assertOk();
    arrivalSchedule($token, $id)->assertOk();
    DriverProfile::query()->update(['status' => DriverProfileStatus::Suspended->value]);

    test()->withToken($token)->postJson("/api/v1/commutes/{$id}/publish")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'DRIVER_NOT_ELIGIBLE')
        ->assertJsonPath('error.message', __('errors.DRIVER_SUSPENDED'));
});

function publishedCommute(string $token): string
{
    $id = minimalCommute($token)->json('data.id');

    saveRoute($token, $id)->assertOk();
    arrivalSchedule($token, $id)->assertOk();
    test()->withToken($token)->postJson("/api/v1/commutes/{$id}/publish")->assertOk();

    return $id;
}
