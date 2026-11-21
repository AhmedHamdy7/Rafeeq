<?php

use App\Domains\Admin\Actions\UpdatePlatformSettingAction;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Models\ContentPage;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Support\DebtLedger;
use App\Domains\Reliability\Models\ReliabilityEvent;
use App\Domains\Safety\Enums\LiveShareSource;
use App\Domains\Safety\Models\EmergencyContact;
use App\Domains\Safety\Models\LiveShare;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Trip\Enums\WarningFeeStatus;
use App\Domains\Trip\Models\MemberWarning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    config(['rafeeq.testing.api' => true]);

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->trips = ScheduledTrip::query()->orderBy('trip_date')->get();
    $this->tripId = $this->trips->first()->id;
});

function od16As(string $token)
{
    return test()->flushHeaders()->withToken($token);
}

function od16Seat(string $driverToken, string $paxToken, string $commuteId, string $tripId): string
{
    return approveSeat($driverToken, requestSeat($paxToken, $commuteId, ['scheduledTripId' => $tripId])->assertStatus(201)->json('data.id'));
}

it('D1: lets a banned passenger finish the ride under way, then shows the hold', function () {
    $paxToken = verifiedPassenger('01112223344');
    $bookingId = od16Seat($this->driverToken, $paxToken, $this->commuteId, $this->tripId);

    underway($this->driverToken, $this->tripId);

    od16As($paxToken)->postJson('/api/v1/testing/account/ban')->assertOk();

    od16As($paxToken)->getJson('/api/v1/home')
        ->assertOk()
        ->assertJsonPath('data.suspension.permanent', true)
        ->assertJsonPath('data.suspension.activeTripId', $this->tripId);

    od16As($paxToken)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.suspension.activeTripId', $this->tripId);
    od16As($paxToken)->getJson("/api/v1/trips/{$this->tripId}")->assertOk();
    od16As($paxToken)->getJson("/api/v1/trips/{$this->tripId}/location")->assertOk();
    od16As($paxToken)->getJson("/api/v1/bookings/{$bookingId}")->assertOk();

    od16As($paxToken)->getJson('/api/v1/my-bookings')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED')
        ->assertJsonPath('error.fields.activeTripId.0', $this->tripId);

    od16As($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/status", ['status' => 'IN_PROGRESS'])->assertOk();
    od16As($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/complete")->assertOk();

    od16As($paxToken)->getJson('/api/v1/home')->assertStatus(403)->assertJsonMissingPath('error.fields.activeTripId');
    od16As($paxToken)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.suspension.activeTripId', null);
    od16As($paxToken)->getJson('/api/v1/support/contact')->assertOk();
});

it('D1: lets a banned driver drive the run under way, and nothing else', function () {
    $paxToken = verifiedPassenger('01112223344');
    od16Seat($this->driverToken, $paxToken, $this->commuteId, $this->tripId);

    underway($this->driverToken, $this->tripId);

    od16As($this->driverToken)->postJson('/api/v1/testing/account/ban')->assertOk();

    od16As($this->driverToken)->getJson('/api/v1/driver/home')->assertOk()->assertJsonPath('data.suspension.activeTripId', $this->tripId);
    od16As($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/status", ['status' => 'IN_PROGRESS'])->assertOk();
    od16As($this->driverToken)->getJson('/api/v1/driver/seat-requests')->assertStatus(403);

    $other = $this->trips->get(1)->id;
    od16As($this->driverToken)->getJson("/api/v1/trips/{$other}")->assertStatus(403);
});

it('D3: asks for a rating on Home after her first ride with a driver, until she dismisses it', function () {
    $paxToken = verifiedPassenger('01112223344');
    $bookingId = od16Seat($this->driverToken, $paxToken, $this->commuteId, $this->tripId);

    $tripId = $this->tripId;
    journeyCompleted($this->driverToken, $tripId);

    od16As($paxToken)->getJson('/api/v1/home')
        ->assertOk()
        ->assertJsonPath('data.ratingPrompt.bookingId', $bookingId)
        ->assertJsonPath('data.ratingPrompt.tripId', $tripId)
        ->assertJsonPath('data.ratingPrompt.reason', 'first_ride');

    od16As($paxToken)->postJson("/api/v1/bookings/{$bookingId}/rating-prompt/dismiss")->assertOk()->assertJsonPath('data.dismissed', true);

    od16As($paxToken)->getJson('/api/v1/home')->assertOk()->assertJsonPath('data.ratingPrompt', null);
});

it('D3: drops the prompt once she rates the ride', function () {
    $paxToken = verifiedPassenger('01112223344');
    $bookingId = od16Seat($this->driverToken, $paxToken, $this->commuteId, $this->tripId);

    journeyCompleted($this->driverToken, $this->tripId);

    od16As($paxToken)->getJson('/api/v1/home')->assertOk()->assertJsonPath('data.ratingPrompt.bookingId', $bookingId);

    rateBooking($paxToken, $bookingId)->assertStatus(201);

    od16As($paxToken)->getJson('/api/v1/home')->assertOk()->assertJsonPath('data.ratingPrompt', null);
});

it('D4: holds a disputed no-show fee out of her next fee line, and brings it back when upheld', function () {
    $paxToken = verifiedPassenger('01112223344');
    $missed = od16Seat($this->driverToken, $paxToken, $this->commuteId, $this->tripId);
    $copy = Booking::query()->findOrFail($missed)->replicate(['live_booking_key']);
    $copy->scheduled_trip_id = $this->trips->get(1)->id;
    $copy->save();
    $next = $copy->id;

    underway($this->driverToken, $this->tripId);
    arrivedAndWaited($this->driverToken, $this->tripId, $missed);
    od16As($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/no-show", ['bookingId' => $missed])->assertOk();
    od16As($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/complete")->assertOk();

    $passenger = User::query()->where('phone_e164', '+201112223344')->sole();
    $warning = MemberWarning::query()->where('booking_id', $missed)->sole();
    $warning->forceFill(['fee_piastres' => 3000, 'fee_status' => WarningFeeStatus::Charged])->save();
    DebtLedger::charge($passenger, DebtEntryType::Fee, 3000, 'no_show', Booking::query()->findOrFail($missed), side: DebtSide::Passenger);

    expect(Booking::query()->findOrFail($next)->fee_line_piastres)->toBe(3000);

    od16As($paxToken)->postJson("/api/v1/bookings/{$missed}/dispute", ['reason' => 'The car left before I got there.'])->assertCreated();

    expect(Booking::query()->findOrFail($next)->fee_line_piastres)->toBe(0);

    od16As($paxToken)->getJson("/api/v1/bookings/{$missed}")
        ->assertOk()
        ->assertJsonPath('data.noShow.feePiastres', 3000)
        ->assertJsonPath('data.noShow.dispute.feeOnHold', true);

    od16As($paxToken)->postJson("/api/v1/testing/bookings/{$missed}/dispute/decide", ['resolution' => 'upheld'])->assertOk();

    expect(Booking::query()->findOrFail($next)->fee_line_piastres)->toBe(3000);

    od16As($paxToken)->getJson("/api/v1/bookings/{$missed}")->assertOk()->assertJsonPath('data.noShow.dispute.feeOnHold', false);
});

it('D6: any edit of a waiting-list place goes back to the driver as a new application', function () {
    $riderToken = verifiedPassenger('01112223344', device: 'pax-1');
    od16Seat($this->driverToken, $riderToken, $this->commuteId, $this->tripId);
    ScheduledTrip::query()->whereKey($this->tripId)->update(['seats_taken' => DB::raw('seats_total')]);

    $waiterToken = verifiedPassenger('01222220001', device: 'pax-2');
    $requestId = requestSeat($waiterToken, $this->commuteId, ['scheduledTripId' => $this->tripId])->assertStatus(201)->json('data.id');
    od16As($this->driverToken)->postJson("/api/v1/driver/seat-requests/{$requestId}/waitlist")->assertOk();

    od16As($waiterToken)->getJson("/api/v1/seat-requests/{$requestId}")->assertOk()->assertJsonPath('data.waitlistPosition', null);

    od16As($waiterToken)->patchJson("/api/v1/seat-requests/{$requestId}", [
        'pickup' => ['lat' => 30.0630, 'lng' => 31.3500, 'label' => 'Rehab gate 2'],
        'dropoff' => ['lat' => 30.0684, 'lng' => 31.1015, 'label' => 'Mehwar, Sheikh Zayed'],
    ])->assertOk()
        ->assertJsonPath('data.status', 'PENDING')
        ->assertJsonPath('data.kind', 'waitlist_application');

    $request = SeatRequest::query()->findOrFail($requestId);

    expect($request->waitlist_position)->toBeNull()
        ->and(round((float) $request->requested_pickup_lat, 4))->toBe(30.063);

    od16As($this->driverToken)->postJson("/api/v1/driver/seat-requests/{$requestId}/waitlist")->assertOk()->assertJsonPath('data.status', 'WAITLISTED');
});

it('D6: has no waiting-list cap unless staff set one', function () {
    expect(config('rafeeq.booking.max_waitlist_size'))->toBe(0);
});

it('D8: records a run where nobody showed up as finished with no passengers, and no hit for the driver', function () {
    $paxToken = verifiedPassenger('01112223344');
    $bookingId = od16Seat($this->driverToken, $paxToken, $this->commuteId, $this->tripId);

    underway($this->driverToken, $this->tripId);
    arrivedAndWaited($this->driverToken, $this->tripId, $bookingId);
    od16As($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/no-show", ['bookingId' => $bookingId])->assertOk();
    od16As($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/complete")->assertOk();

    od16As($this->driverToken)->getJson("/api/v1/trips/{$this->tripId}")
        ->assertOk()
        ->assertJsonPath('data.summary.carried', 0)
        ->assertJsonPath('data.summary.noShows', 1)
        ->assertJsonPath('data.summary.outcome', 'no_passengers');

    $driverId = User::query()->where('phone_e164', '+201012345678')->sole()->id;

    expect(ReliabilityEvent::query()->where('user_id', $driverId)->count())->toBe(0);
});

it('D9: texts only the contact picked for auto-share, once, and auto-shares to exactly one', function () {
    $token = verifiedPassenger('01112223344');

    $first = od16As($token)->postJson('/api/v1/safety/emergency-contacts', ['name' => 'Mama', 'phone' => '01099998888'])->assertStatus(201)->json('data.id');
    $second = od16As($token)->postJson('/api/v1/safety/emergency-contacts', ['name' => 'Sara', 'phone' => '01099997777'])->assertStatus(201)->json('data.id');

    expect(od16As($token)->getJson('/api/v1/testing/safety/contact-messages')->assertOk()->json('data'))->toBeEmpty();

    od16As($token)->patchJson("/api/v1/safety/emergency-contacts/{$first}", ['autoShareTrips' => true])->assertOk()->assertJsonPath('data.autoShareTrips', true);

    $messages = od16As($token)->getJson('/api/v1/testing/safety/contact-messages')->assertOk()->json('data');

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['contactId'])->toBe($first)
        ->and($messages[0]['status'])->toBe('SENT')
        ->and($messages[0]['text'])->toContain('trusted contact on YuGo');

    od16As($token)->patchJson("/api/v1/safety/emergency-contacts/{$first}", ['autoShareTrips' => true])->assertOk();
    od16As($token)->patchJson("/api/v1/safety/emergency-contacts/{$second}", ['autoShareTrips' => true])->assertOk();

    expect(od16As($token)->getJson('/api/v1/testing/safety/contact-messages')->json('data'))->toHaveCount(2)
        ->and(EmergencyContact::query()->findOrFail($first)->auto_share_trips)->toBeFalse()
        ->and(EmergencyContact::query()->findOrFail($second)->auto_share_trips)->toBeTrue();

    od16As($token)->getJson('/api/v1/safety/emergency-contacts')->assertOk()->assertJsonPath('meta.autoShareContactId', $second);
});

it('D10: sends the live link at once when auto-share is switched on mid-trip, to the chosen contact only', function () {
    $paxToken = verifiedPassenger('01112223344');
    od16Seat($this->driverToken, $paxToken, $this->commuteId, $this->tripId);

    od16As($paxToken)->postJson('/api/v1/safety/emergency-contacts', ['name' => 'Mama', 'phone' => '01099998888'])->assertStatus(201);
    $chosen = od16As($paxToken)->postJson('/api/v1/safety/emergency-contacts', ['name' => 'Sara', 'phone' => '01099997777'])->assertStatus(201)->json('data.id');

    underway($this->driverToken, $this->tripId);

    od16As($paxToken)->putJson('/api/v1/safety/auto-share', ['enabled' => true, 'contactId' => $chosen])
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.contactId', $chosen);

    $shares = LiveShare::query()->where('source', LiveShareSource::Auto->value)->get();

    expect($shares)->toHaveCount(1)
        ->and($shares->first()->shared_with_contact_id)->toBe($chosen);

    od16As($paxToken)->getJson('/api/v1/safety/live-shares')->assertOk();
});

it('D11: refuses to empty or reset the InstaPay handle and name', function () {
    $admin = AdminUser::factory()->create();
    $action = app(UpdatePlatformSettingAction::class);

    expect(fn () => $action->updateText('debt.instapay_handle', '  ', $admin, 'Clearing it for the test.'))->toThrow(DomainException::class)
        ->and(fn () => $action->reset('debt.instapay_name', $admin, 'Clearing it for the test.'))->toThrow(DomainException::class);
});

it('D12: answers the support contact from the dashboard settings, also to a banned member', function () {
    $token = verifiedPassenger('01112223344');

    od16As($token)->getJson('/api/v1/support/contact')
        ->assertOk()
        ->assertJsonPath('data.email', 'sahla.aao007@gmail.com')
        ->assertJsonPath('data.workingHours', null)
        ->assertJsonPath('data.chat', null);

    $admin = AdminUser::factory()->create();
    app(UpdatePlatformSettingAction::class)->updateText('support.working_hours_en', 'Sun–Thu, 9:00–18:00', $admin, 'Support hours for the test.');
    app(UpdatePlatformSettingAction::class)->updateText('support.working_hours_ar', 'الأحد–الخميس', $admin, 'Support hours for the test.');

    od16As($token)->postJson('/api/v1/testing/account/ban')->assertOk();

    od16As($token)->getJson('/api/v1/support/contact')
        ->assertOk()
        ->assertJsonPath('data.workingHours.en', 'Sun–Thu, 9:00–18:00')
        ->assertJsonPath('data.responseTime', null);
});

it('D13: answers the guidelines once staff wrote them, 404 while empty, also to a banned member', function () {
    $token = verifiedPassenger('01112223344');

    od16As($token)->getJson('/api/v1/content/guidelines')->assertNotFound();

    $page = new ContentPage;
    $page->key = 'guidelines';
    $page->body_en = '';
    $page->body_ar = '';
    $page->version = 1;
    $page->save();

    od16As($token)->getJson('/api/v1/content/guidelines')->assertNotFound();

    $page->forceFill(['body_en' => 'Be kind.', 'body_ar' => 'خليك لطيف.'])->save();

    od16As($token)->postJson('/api/v1/testing/account/ban')->assertOk();

    od16As($token)->getJson('/api/v1/content/guidelines')
        ->assertOk()
        ->assertJsonPath('data.key', 'guidelines')
        ->assertJsonPath('data.en', 'Be kind.')
        ->assertJsonPath('data.ar', 'خليك لطيف.');
});
