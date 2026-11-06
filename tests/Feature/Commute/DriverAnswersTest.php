<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Actions\RecomputeDepartureAction;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Commute\Support\ScheduleLock;
use App\Domains\Driver\Actions\VehicleSuspensionAction;
use App\Domains\Driver\Actions\WarnExpiringRegistrationsAction;
use App\Domains\Driver\Enums\VehicleDocumentType;
use App\Domains\Driver\Enums\VehicleSuspensionReason;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Driver\Models\VehicleDocument;
use App\Domains\Notification\Models\Notification;
use App\Domains\Payment\Support\DriverFees;
use App\Domains\Reliability\Enums\ReliabilityEventKind;
use App\Domains\Reliability\Support\ReliabilitySettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');

    $this->driver = approvedDriver();
    $this->vehicle = Vehicle::sole();
    $this->commute = readyCommute($this->driver, $this->vehicle->id, ['seatsTotal' => 3]);

    test()->withToken($this->driver)->postJson("/api/v1/commutes/{$this->commute}/publish")->assertOk();

    $this->days = ScheduledTrip::query()->where('commute_offer_id', $this->commute)->orderBy('trip_date')->get();
});

function daPost(string $token, string $url, array $body = [])
{
    return test()->flushHeaders()->withToken($token)->postJson($url, $body);
}

function daRecurring(string $token, string $commute)
{
    return requestSeat($token, $commute, ['commitment' => 'recurring', 'requestedDaysMask' => 62])->assertStatus(201)->json('data.id');
}

it('locks a day 12 hours before departure and keeps the fee tiers', function () {
    $day = $this->days->first();

    $day->forceFill(['departure_at' => now()->addHours(13)])->save();
    expect(ScheduleLock::hours())->toBe(12)->and(ScheduleLock::isLocked($day))->toBeFalse();

    $day->forceFill(['departure_at' => now()->addHours(11)])->save();
    expect(ScheduleLock::isLocked($day))->toBeTrue();

    expect(DriverFees::cancelPercent(now()->addHours(17)))->toBe(0)
        ->and(DriverFees::cancelPercent(now()->addHours(13)))->toBeGreaterThan(0);

    expect(ReliabilityEventKind::DriverCancelledDay->weight(13.0))->toBe(ReliabilitySettings::driverCancelMidNotice());
});

it('seats the other days of a recurring request when one is full, and waits for that day', function () {
    $full = $this->days->first();
    $full->forceFill(['seats_taken' => 3])->save();

    $pax = verifiedPassenger('01223339999', device: 'pax-2');
    $request = daRecurring($pax, $this->commute);

    $data = daPost($this->driver, "/api/v1/driver/seat-requests/{$request}/approve")->assertStatus(201)->json('data');

    expect($data['seatedDays'])->toBeGreaterThan(0)
        ->and($data['skippedDays'][0]['date'])->toBe($full->trip_date->toDateString())
        ->and($data['skippedDays'][0]['reason'])->toBe('full');

    $waiting = SeatRequest::query()->where('commute_offer_id', $this->commute)->where('status', 'waitlisted')->sole();

    expect($waiting->scheduled_trip_id)->toBe($full->id)
        ->and(Notification::query()->where('type', 'seat_approved_partly')->count())->toBe(1);
});

it('lets the skipped day be accepted later without touching the standing request', function () {
    $full = $this->days->first();
    $full->forceFill(['seats_taken' => 3])->save();

    $pax = verifiedPassenger('01223339999', device: 'pax-2');
    $request = daRecurring($pax, $this->commute);
    daPost($this->driver, "/api/v1/driver/seat-requests/{$request}/approve")->assertStatus(201);

    $full->forceFill(['seats_taken' => 2])->save();

    $waiting = SeatRequest::query()->where('commute_offer_id', $this->commute)->where('status', 'waitlisted')->sole();

    daPost($this->driver, "/api/v1/driver/commutes/{$this->commute}/waitlist/offers", ['seatRequestIds' => [$waiting->id]])->assertOk();

    $offer = test()->flushHeaders()->withToken($pax)->getJson('/api/v1/seat-offers?status=OPEN')->assertOk()->json('data.0.id');

    daPost($pax, "/api/v1/seat-offers/{$offer}/accept")->assertOk();

    expect(SeatRequest::findOrFail($request)->status->value)->toBe('approved')
        ->and(Booking::query()->where('scheduled_trip_id', $full->id)->where('status', 'confirmed')->count())->toBe(1);
});

it('skips a locked day that would break the arrival, not the whole approval', function () {
    $locked = $this->days->first();
    $locked->forceFill([
        'departure_at' => now()->addHours(5),
        'promised_arrival_at' => now()->addHours(5)->addMinute(),
    ])->save();

    $pax = verifiedPassenger('01223339999', device: 'pax-2');
    $request = daRecurring($pax, $this->commute);

    $data = daPost($this->driver, "/api/v1/driver/seat-requests/{$request}/approve")->assertStatus(201)->json('data');

    $reasons = collect($data['skippedDays'])->keyBy('date');

    expect($data['seatedDays'])->toBeGreaterThan(0)
        ->and($reasons[$locked->trip_date->toDateString()]['reason'])->toBe('breaks_arrival');
});

it('tells confirmed passengers their new pickup time, and never moves the departure later', function () {
    $day = $this->days->first();
    $pax = verifiedPassenger('01223339999', device: 'pax-2');
    $booking = approveSeat($this->driver, requestSeat($pax, $this->commute, ['scheduledTripId' => $day->id])->assertStatus(201)->json('data.id'));

    Notification::query()->delete();
    $day->refresh();
    $day->forceFill(['departure_at' => $day->departure_at->copy()->addMinutes(40), 'departure_local' => $day->departure_local->copy()->addMinutes(40)])->save();

    expect(app(RecomputeDepartureAction::class)->execute($day->id))->toBeTrue();

    $note = Notification::query()->where('type', 'pickup_time_changed')->sole();

    expect($note->data['bookingId'] ?? null)->toBe($booking)
        ->and($note->data['pickupEta'] ?? null)->not->toBeNull();

    Notification::query()->delete();
    $day->refresh();
    $day->forceFill(['departure_at' => $day->departure_at->copy()->subMinutes(60), 'departure_local' => $day->departure_local->copy()->subMinutes(60)])->save();

    expect(app(RecomputeDepartureAction::class)->execute($day->id))->toBeFalse()
        ->and(Notification::query()->where('type', 'pickup_time_changed')->exists())->toBeFalse();
});

it('suspends a car whose registration has expired, and pauses its commutes', function () {
    $this->vehicle->documents()->where('type', VehicleDocumentType::Registration->value)->delete();
    VehicleDocument::factory()->create(['vehicle_id' => $this->vehicle->id, 'type' => VehicleDocumentType::Registration, 'expires_at' => now()->subDay()]);

    expect(app(VehicleSuspensionAction::class)->sweepExpiredRegistrations())->toBe(1);

    $this->vehicle->refresh();

    expect($this->vehicle->verification_status)->toBe(VehicleVerificationStatus::Suspended)
        ->and($this->vehicle->suspension_reason)->toBe('registration_expired')
        ->and(CommuteOffer::findOrFail($this->commute)->paused_reason->value)->toBe('vehicle_suspended');

    test()->flushHeaders()->withToken($this->driver)->getJson('/api/v1/driver/vehicles')->assertOk()->assertJsonPath('data.0.suspensionReason', 'registration_expired');

    $items = collect(test()->flushHeaders()->withToken($this->driver)->getJson('/api/v1/driver/verification')->json('data.items'))->keyBy('key');

    expect($items['vehicle_registration']['status'])->toBe('expired')
        ->and($items['vehicle_details']['status'])->toBe('suspended')
        ->and($items['driving_licence']['status'])->toBe('approved');

    daPost($this->driver, "/api/v1/commutes/{$this->commute}/resume")->assertStatus(422)->assertJsonPath('error.code', 'COMMUTE_VEHICLE_UNAVAILABLE');
});

it('takes a renewed registration from upload through review and back to approved', function () {
    $this->vehicle->documents()->where('type', VehicleDocumentType::Registration->value)->delete();
    VehicleDocument::factory()->create(['vehicle_id' => $this->vehicle->id, 'type' => VehicleDocumentType::Registration, 'expires_at' => now()->subDay()]);
    app(VehicleSuspensionAction::class)->sweepExpiredRegistrations();

    test()->flushHeaders()->withToken($this->driver)
        ->post("/api/v1/driver/vehicles/{$this->vehicle->id}/documents", [
            'type' => VehicleDocumentType::Registration->value,
            'file' => UploadedFile::fake()->image('reg.jpg'),
        ], ['Accept' => 'application/json'])->assertSuccessful();

    $items = collect(test()->flushHeaders()->withToken($this->driver)->getJson('/api/v1/driver/verification')->json('data.items'))->keyBy('key');

    expect($items['vehicle_registration']['status'])->toBe('under_review');

    app(VehicleSuspensionAction::class)->approveRenewal($this->vehicle->refresh(), adminWithRole(AdminRole::Verification), now()->addYear()->toDateString());

    $this->vehicle->refresh();

    expect($this->vehicle->verification_status)->toBe(VehicleVerificationStatus::Approved)
        ->and($this->vehicle->suspension_reason)->toBeNull();

    daPost($this->driver, "/api/v1/commutes/{$this->commute}/resume")->assertOk();
});

it('does not let a driver upload their way out of a suspension after a report', function () {
    app(VehicleSuspensionAction::class)->suspend($this->vehicle, VehicleSuspensionReason::Report, adminWithRole(AdminRole::Verification), 'Wrong car');

    test()->flushHeaders()->withToken($this->driver)
        ->post("/api/v1/driver/vehicles/{$this->vehicle->id}/documents", [
            'type' => VehicleDocumentType::Registration->value,
            'file' => UploadedFile::fake()->image('reg.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(409);

    expect(Notification::query()->where('type', 'vehicle_suspended')->exists())->toBeTrue();

    app(VehicleSuspensionAction::class)->reinstate($this->vehicle->refresh(), adminWithRole(AdminRole::Verification));

    expect($this->vehicle->refresh()->verification_status)->toBe(VehicleVerificationStatus::Approved);
});

it('warns once from 30 days before the registration expires', function () {
    $this->vehicle->documents()->where('type', VehicleDocumentType::Registration->value)->delete();
    VehicleDocument::factory()->create(['vehicle_id' => $this->vehicle->id, 'type' => VehicleDocumentType::Registration, 'expires_at' => now()->addDays(20)]);

    expect(app(WarnExpiringRegistrationsAction::class)->sweep())->toBe(1)
        ->and(app(WarnExpiringRegistrationsAction::class)->sweep())->toBe(0);

    expect(Notification::query()->where('type', 'driver_item_expiring')->latest('id')->first()->data['itemKey'] ?? null)->toBe('vehicle_registration');
});
