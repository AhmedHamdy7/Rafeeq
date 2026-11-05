<?php

namespace Database\Seeders;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\PaymentStatus;
use App\Domains\Booking\Enums\PaymentType;
use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Booking\Enums\SeatRequestCommitment;
use App\Domains\Booking\Enums\SeatRequestKind;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\SameTimeBookings;
use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Enums\CommuteDirection;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\CommuteRuleKey;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Commute\Support\DepartureTimeCalculator;
use App\Domains\Commute\Support\SeatPricing;
use App\Domains\Driver\Enums\VehicleDocumentType;
use App\Domains\Driver\Enums\VehicleTier;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Driver\Models\VehicleDocument;
use App\Domains\Geo\Models\Corridor;
use App\Domains\Geo\Models\Place;
use App\Domains\Group\Enums\CommuteGroupStatus;
use App\Domains\Group\Enums\GroupMemberRole;
use App\Domains\Group\Enums\GroupMemberStatus;
use App\Domains\Group\Models\CommuteGroup;
use App\Domains\Group\Models\GroupMember;
use App\Domains\Identity\Actions\EnrollInitialRoleAction;
use App\Domains\Identity\Enums\Capability;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserStat;
use App\Domains\Matching\Actions\MatchDemandToExistingCommutesAction;
use App\Domains\Matching\Enums\CommuteDemandStatus;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Matching\Models\SearchHistory;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Models\Notification;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Payment\Actions\SettleBookingAction;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtAccount;
use App\Domains\Payment\Support\DebtLedger;
use App\Domains\Payment\Support\DebtStanding;
use App\Domains\Rating\Actions\SubmitRatingAction;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\DaysMask;
use App\Domains\Trip\Enums\TripSessionStatus;
use App\Domains\Trip\Models\TripSession;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\UserVerification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Omar's and Ahmed's accounts with everything on them, as a driver AND as a passenger, and with
 * trips on the days ahead — so the people building the client can open any screen from one sign-in
 * instead of switching between single-state demo accounts.
 *
 * As a **driver**: the five items verified, a reviewed car, a weekday commute home and a weekend
 * commute, riders on the upcoming days, an inbox with every kind of request, a waiting list, and
 * past runs with ratings and commission.
 *
 * As a **passenger**: recurring seats on the days ahead, a trial seat on the other tester's weekend
 * commute, a pending request, a counteroffer, a waiting-list place, an open seat offer, every status
 * in History, an active and a paused demand, recent searches and notifications.
 *
 * 🔴 **Safe to run again, and meant to be.** `demo:refresh` calls it: what exists is left alone, and
 * the seats are topped back up onto the days ahead as the calendar moves. It also pays off commission
 * from earlier weeks, because a commission the seed wrote last week would otherwise go overdue and
 * stop them publishing, which no tester asked for. Refused in production by its callers.
 *
 * 🔒 The riders are fictional, in the demo phone block. The two times per tester never overlap with
 * anything they ride themselves: they ride mornings, and drive afternoons and weekends.
 */
final class TesterAccountsSeeder extends DriverHomeStatesSeeder
{
    private const array ACCOUNTS = [
        '+201125847213' => [
            'other' => '+201014531739', 'riders' => 10, 'plate' => 'أ ح م 2145', 'make' => 'Hyundai', 'model' => 'Elantra',
            'colour' => 'فضي', 'tier' => VehicleTier::Comfort, 'weekday' => '16:00:00', 'weekend' => '13:00:00',
        ],
        '+201014531739' => [
            'other' => '+201125847213', 'riders' => 30, 'plate' => 'ع م ر 3178', 'make' => 'Toyota', 'model' => 'Corolla',
            'colour' => 'أبيض', 'tier' => VehicleTier::Standard, 'weekday' => '17:00:00', 'weekend' => '10:00:00',
        ],
    ];

    private const array RIDER_NAMES = ['هنا', 'سارة', 'ليلى', 'نهى', 'دينا', 'رنا', 'مي', 'أمل', 'شيماء', 'ريم'];

    private const int WEEKEND_MASK = DaysMask::FRIDAY | DaysMask::SATURDAY;

    // How many upcoming days each recurring seat is kept on.
    private const int DAYS_AHEAD = 10;

    public function run(): void
    {
        $this->applyTesterAccounts();
    }

    /**
     * @return list<string> one line per account, for the command that ran it
     */
    public function applyTesterAccounts(): array
    {
        $commutes = [];

        foreach (self::ACCOUNTS as $phone => $spec) {
            $tester = User::query()->where('phone_e164', $phone)->first();

            if ($tester !== null) {
                $commutes[$phone] = $this->makeDriver($tester, $spec);
            }
        }

        // The real generator, as `DemoDataSeeder` uses, so the new commutes have their days.
        Artisan::call('commutes:generate-trips');

        $report = [];

        foreach ($commutes as $phone => [$weekday, $weekend]) {
            $tester = User::query()->where('phone_e164', $phone)->firstOrFail();
            $spec = self::ACCOUNTS[$phone];

            $this->promiseEveryDay($weekday);
            $this->promiseEveryDay($weekend);
            $this->driverSide($tester, $weekday, $weekend, $spec);
            $this->passengerSide($tester, $commutes[$spec['other']][1] ?? null);
            $this->payOffEarlierWeeks($tester);

            $report[] = sprintf(
                '%s  driving %d upcoming days, riding %d upcoming days',
                $phone,
                ScheduledTrip::query()->whereIn('commute_offer_id', [$weekday->id, $weekend->id])->where('departure_at', '>', now())->count(),
                Booking::query()->where('passenger_user_id', $tester->id)->where('status', BookingStatus::Confirmed->value)
                    ->whereHas('scheduledTrip', fn ($trip) => $trip->where('departure_at', '>', now()))->count(),
            );
        }

        return $report;
    }

    /*
    |--------------------------------------------------------------------------
    | The driver: verified, a reviewed car, two commutes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $spec
     * @return array{0: CommuteOffer, 1: CommuteOffer} the weekday commute home, and the weekend one
     */
    private function makeDriver(User $tester, array $spec): array
    {
        $admin = AdminUser::query()->firstOrFail();

        EnrollInitialRoleAction::grant($tester, [Capability::Passenger, Capability::Driver]);

        foreach ([VerificationType::Phone, VerificationType::GovernmentId, VerificationType::Selfie, VerificationType::DrivingLicence] as $type) {
            $verification = UserVerification::query()->where('user_id', $tester->id)->where('type', $type->value)->first();

            if ($verification === null) {
                UserVerification::factory()->approved()->create([
                    'user_id' => $tester->id,
                    'type' => $type,
                    'reviewed_by' => $admin->id,
                    'expires_at' => $type === VerificationType::DrivingLicence ? now()->addYears(3) : null,
                ]);
            }
        }

        $profile = DriverProfile::query()->where('user_id', $tester->id)->first()
            ?? DriverProfile::factory()->approved()->create([
                'user_id' => $tester->id,
                'reviewer_id' => $admin->id,
                'completed_trips_count' => 64,
                'cancellation_rate' => 1.50,
                'on_time_rate' => 96.0,
            ]);

        $vehicle = Vehicle::query()->where('driver_profile_id', $profile->user_id)->first()
            ?? Vehicle::factory()->create([
                'driver_profile_id' => $profile->user_id,
                'make' => $spec['make'],
                'model' => $spec['model'],
                'year' => 2021,
                'colour' => $spec['colour'],
                'plate_number' => $spec['plate'],
                'plate_normalized' => Vehicle::normalizePlate($spec['plate']),
                'seats' => 5,
                'approved_seats' => 4,
                'tier' => $spec['tier'],
                'has_ac' => true,
                'is_active' => true,
                'verification_status' => VehicleVerificationStatus::Approved,
            ]);

        foreach ([VehicleDocumentType::RegistrationFront, VehicleDocumentType::RegistrationBack] as $type) {
            if (! VehicleDocument::query()->where('vehicle_id', $vehicle->id)->where('type', $type->value)->exists()) {
                VehicleDocument::factory()->create(['vehicle_id' => $vehicle->id, 'type' => $type]);
            }
        }

        $corridor = Corridor::query()->firstOrFail();
        $rehab = Place::query()->where('name', 'like', '%الرحاب%')->first() ?? Place::query()->firstOrFail();
        $village = Place::query()->where('id', '!=', $rehab->id)->firstOrFail();

        $shared = [
            'tier' => $spec['tier'],
            'seats' => 4,
            'audience' => CommuteAudience::AnyVerified,
            'rules' => [CommuteRuleKey::NonSmoking, CommuteRuleKey::Ac],
        ];

        $weekday = $this->commute($profile, CommuteDirection::ToHome)
            ?? $this->publishCommute($profile, $vehicle, [
                ...$shared,
                'time' => $spec['weekday'],
                'direction' => CommuteDirection::ToHome,
                'originAddress' => 'القرية الذكية، مبنى B6',
                'destinationAddress' => 'الرحاب، بوابة ٢',
                'pickups' => [['lat' => 30.0710, 'lng' => 31.0300, 'label' => 'Smart Village main gate']],
                'dropoffs' => [['lat' => 30.0650, 'lng' => 31.4300, 'label' => 'Ring Road, Suez Road exit'], ['lat' => 30.0590, 'lng' => 31.4700, 'label' => 'Rehab Mall 2 gate']],
            ], $corridor, $village, $rehab);

        $weekend = $this->commute($profile, CommuteDirection::ToWork)
            ?? $this->publishCommute($profile, $vehicle, [
                ...$shared,
                'time' => $spec['weekend'],
                'daysMask' => self::WEEKEND_MASK,
                'pickups' => [['lat' => 30.0590, 'lng' => 31.4700, 'label' => 'Rehab Mall 2 gate']],
                'dropoffs' => [['lat' => 30.0710, 'lng' => 31.0300, 'label' => 'Smart Village main gate']],
            ], $corridor, $rehab, $village);

        return [$weekday, $weekend];
    }

    private function commute(DriverProfile $profile, CommuteDirection $direction): ?CommuteOffer
    {
        return CommuteOffer::query()
            ->where('driver_profile_id', $profile->user_id)
            ->where('direction', $direction->value)
            ->where('status', CommuteOfferStatus::Published->value)
            ->orderBy('id')
            ->first();
    }

    private function promiseEveryDay(CommuteOffer $offer): void
    {
        foreach ($this->upcoming($offer, 40) as $day) {
            if ($day->promised_arrival_at === null) {
                $day->forceFill(['promised_arrival_at' => $day->departure_at->copy()->addSeconds((int) $offer->route_duration_seconds + 900)])->save();
            }
        }
    }

    /**
     * Riders on the days ahead, and every kind of request waiting for an answer.
     *
     * @param  array<string, mixed>  $spec
     */
    private function driverSide(User $tester, CommuteOffer $weekday, CommuteOffer $weekend, array $spec): void
    {
        $riders = $this->ridersFor($spec['riders']);
        $group = $this->groupOf($weekday);
        $days = $this->upcoming($weekday);

        // Three regulars on every day ahead, with one day made full by a fourth.
        foreach ([$riders[0], $riders[1], $riders[2]] as $rider) {
            $request = $this->ask($weekday, $rider, ['status' => SeatRequestStatus::Approved, 'commitment' => SeatRequestCommitment::Recurring, 'requested_days_mask' => DaysMask::weekdaysSunToThu()->value, 'responded_at' => now()->subDays(20)]);
            $this->member($group, $rider, GroupMemberRole::Member, DaysMask::weekdaysSunToThu()->value);

            foreach ($days as $day) {
                $this->seat($rider, $day, $group, $request);
            }
        }

        $fullDay = $days->get(1);

        if ($fullDay !== null) {
            $request = $this->ask($weekday, $riders[3], ['status' => SeatRequestStatus::Approved, 'scheduled_trip_id' => $fullDay->id, 'responded_at' => now()->subDay()]);
            $this->member($group, $riders[3], GroupMemberRole::Trial, null);
            $this->seat($riders[3], $fullDay, $group, $request);

            // A full day's request is an application to join the waiting list (R-1).
            $this->ask($weekday, $riders[4], ['kind' => SeatRequestKind::WaitlistApplication, 'scheduled_trip_id' => $fullDay->id]);
        }

        // The inbox: a recurring request and a one-day trial.
        $this->ask($weekday, $riders[5], ['commitment' => SeatRequestCommitment::Recurring, 'requested_days_mask' => DaysMask::weekdaysSunToThu()->value, 'intro_message' => 'أهلاً! بشتغل في القرية الذكية وساكنة في الرحاب.']);
        $this->ask($weekday, $riders[6], ['scheduled_trip_id' => $days->get(3)?->id, 'intro_message' => 'ممكن أجرب يوم الأول؟']);

        // What he already answered: a counteroffer waiting on her, a place on the list, a no.
        $this->counteroffered($weekday, $riders[7], $tester);
        $this->ask($weekday, $riders[8], ['status' => SeatRequestStatus::Waitlisted, 'waitlist_position' => 1, 'responded_at' => now()->subDays(2), 'commitment' => SeatRequestCommitment::Recurring, 'requested_days_mask' => DaysMask::weekdaysSunToThu()->value]);
        $this->ask($weekday, $riders[9], ['status' => SeatRequestStatus::Rejected, 'responded_at' => now()->subDays(3), 'responded_by' => $tester->id, 'response_note' => 'العربية مش هتعدي على نقطتك.']);

        // The weekend commute: one regular, and one request to answer.
        $weekendGroup = $this->groupOf($weekend);
        $regular = $this->ask($weekend, $riders[0], ['status' => SeatRequestStatus::Approved, 'commitment' => SeatRequestCommitment::Recurring, 'requested_days_mask' => self::WEEKEND_MASK, 'responded_at' => now()->subDays(10)]);
        $this->member($weekendGroup, $riders[0], GroupMemberRole::Member, self::WEEKEND_MASK);

        foreach ($this->upcoming($weekend) as $day) {
            $this->seat($riders[0], $day, $weekendGroup, $regular);
        }

        $this->ask($weekend, $riders[1], ['commitment' => SeatRequestCommitment::Recurring, 'requested_days_mask' => self::WEEKEND_MASK]);

        $this->pastRuns($tester, $weekday, $group, $riders);
    }

    /**
     * The runs he already drove, on the last few days: ratings both ways, a rider who did not come,
     * and the commission that puts on his Payments screen. Once only.
     *
     * @param  list<User>  $riders
     */
    private function pastRuns(User $tester, CommuteOffer $offer, CommuteGroup $group, array $riders): void
    {
        $alreadyDriven = ScheduledTrip::query()
            ->where('commute_offer_id', $offer->id)
            ->where('status', ScheduledTripStatus::Completed->value)
            ->exists();

        if ($alreadyDriven) {
            return;
        }

        foreach (range(1, 6) as $daysAgo) {
            $trip = $this->pastDay($offer, $daysAgo);
            $request = SeatRequest::query()->where('commute_offer_id', $offer->id)->where('passenger_user_id', $riders[0]->id)->first();

            $first = $this->pastSeat($riders[0], $trip, $group, $request, BookingStatus::Completed);
            $second = $this->pastSeat($riders[1], $trip, $group, null, $daysAgo === 3 ? BookingStatus::NoShow : BookingStatus::Completed);

            foreach ([$first, $second] as $booking) {
                if ($booking->status === BookingStatus::Completed) {
                    app(SettleBookingAction::class)->execute($booking);
                }
            }

            // Both rated on the first three runs, so those are revealed and his stars are a number.
            if ($daysAgo <= 3) {
                $ratings = app(SubmitRatingAction::class);
                $ratings->submit($riders[0], $first->refresh(), ['stars' => 6 - min($daysAgo, 2), 'comment' => 'سواقة هادية والمواعيد مظبوطة.', 'tags' => ['on_time', 'safe_driving']]);
                $ratings->submit($tester, $first->refresh(), ['stars' => 5, 'comment' => 'راكبة ملتزمة بالمواعيد.', 'tags' => ['on_time']]);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The passenger: seats ahead, open requests, History, demands
    |--------------------------------------------------------------------------
    */

    private function passengerSide(User $tester, ?CommuteOffer $otherTestersWeekend): void
    {
        $mona = $this->demoCommute('+201055550002');

        if ($mona !== null) {
            // Her regular commute: a seat on every day ahead, as a member of the group.
            $group = $this->groupOf($mona);
            $request = $this->ask($mona, $tester, ['status' => SeatRequestStatus::Approved, 'commitment' => SeatRequestCommitment::Recurring, 'requested_days_mask' => DaysMask::weekdaysSunToThu()->value, 'responded_at' => now()->subDays(25)]);
            $this->member($group, $tester, GroupMemberRole::Member, DaysMask::weekdaysSunToThu()->value);

            foreach ($this->upcoming($mona) as $day) {
                $this->seat($tester, $day, $group, $request);
            }

            $this->history($tester, $mona, $group);
        }

        // A trial weekend seat with the other tester, so each is the other's passenger too.
        if ($otherTestersWeekend !== null) {
            $day = $this->upcoming($otherTestersWeekend)->first();

            if ($day !== null) {
                $group = $this->groupOf($otherTestersWeekend);
                $request = $this->ask($otherTestersWeekend, $tester, ['status' => SeatRequestStatus::Approved, 'scheduled_trip_id' => $day->id, 'responded_at' => now()]);
                $this->member($group, $tester, GroupMemberRole::Trial, null);
                $this->seat($tester, $day, $group, $request);
            }
        }

        $this->openRequests($tester);
        $this->demandsAndSearches($tester);
        $this->notify($tester);
    }

    private function openRequests(User $tester): void
    {
        $hala = $this->demoCommute('+201055550001');
        $dalia = $this->demoCommute('+201055550004');
        $salma = $this->demoCommute('+201055550003');
        $yasmin = $this->demoCommute('+201055550005');

        if ($hala !== null) {
            $this->ask($hala, $tester, ['scheduled_trip_id' => $this->upcoming($hala)->get(2)?->id, 'intro_message' => 'أهلاً! ينفع أجرب معاكي يوم؟']);
        }

        if ($dalia !== null) {
            $this->counteroffered($dalia, $tester, $dalia->driverProfile->user);
        }

        if ($salma !== null) {
            $this->ask($salma, $tester, ['status' => SeatRequestStatus::Waitlisted, 'waitlist_position' => 2, 'responded_at' => now()->subDays(4), 'commitment' => SeatRequestCommitment::Recurring, 'requested_days_mask' => DaysMask::weekdaysSunToThu()->value]);
        }

        if ($yasmin !== null) {
            $day = $this->upcoming($yasmin)->first();
            $place = $this->ask($yasmin, $tester, ['status' => SeatRequestStatus::Waitlisted, 'waitlist_position' => 1, 'responded_at' => now()->subDays(2), 'scheduled_trip_id' => $day?->id]);

            // A freed seat offered to her: the one she can act on, clashing with her regular seat.
            $hasOpen = SeatOffer::query()->where('seat_request_id', $place->id)->where('status', SeatOfferStatus::Open->value)->exists();

            if ($day !== null && ! $hasOpen) {
                (new SeatOffer)->forceFill([
                    'scheduled_trip_id' => $day->id,
                    'seat_request_id' => $place->id,
                    'status' => SeatOfferStatus::Open->value,
                    'release_event_id' => (string) Str::ulid(),
                ])->save();
            }
        }
    }

    /**
     * Every status the History tab draws, on the days just gone. Once only.
     */
    private function history(User $tester, CommuteOffer $offer, CommuteGroup $group): void
    {
        $hasHistory = Booking::query()
            ->where('passenger_user_id', $tester->id)
            ->where('status', BookingStatus::Completed->value)
            ->exists();

        if ($hasHistory) {
            return;
        }

        $ratings = app(SubmitRatingAction::class);
        $driver = $offer->loadMissing('driverProfile.user')->driverProfile->user;

        foreach ([
            1 => BookingStatus::Completed, 2 => BookingStatus::Completed, 3 => BookingStatus::Completed,
            4 => BookingStatus::CancelledByDriver, 5 => BookingStatus::EndedEarly, 6 => BookingStatus::Completed,
            7 => BookingStatus::NoShow, 8 => BookingStatus::CancelledByPassenger, 9 => BookingStatus::Completed,
        ] as $daysAgo => $status) {
            $booking = $this->pastSeat($tester, $this->pastDay($offer, $daysAgo), $group, null, $status);

            if ($status === BookingStatus::Completed) {
                app(SettleBookingAction::class)->execute($booking);
            }

            // Day 1 rated by both (revealed), day 2 by her only (still editable), day 3 left to rate.
            if ($daysAgo === 1) {
                $ratings->submit($tester, $booking->refresh(), ['stars' => 5, 'comment' => 'رحلة ممتازة، المواعيد مظبوطة.', 'tags' => ['on_time', 'clean_car']]);
                $ratings->submit($driver, $booking->refresh(), ['stars' => 5, 'comment' => 'راكب ملتزم.', 'tags' => ['on_time']]);
            }

            if ($daysAgo === 2) {
                $ratings->submit($tester, $booking->refresh(), ['stars' => 4, 'tags' => ['safe_driving']]);
            }

            if ($daysAgo === 4) {
                $ratings->submit($tester, $booking->refresh(), ['stars' => 2]);
            }
        }
    }

    private function demandsAndSearches(User $tester): void
    {
        $rehab = ['lat' => 30.0594, 'lng' => 31.4913, 'label' => 'Rehab Gate 2'];
        $village = ['lat' => 30.0714, 'lng' => 31.0300, 'label' => 'Smart Village B6'];

        // `DemoDataSeeder` already saved one active demand; a paused one beside it.
        $demands = CommuteDemand::query()->where('passenger_user_id', $tester->id)->orderBy('id')->get();

        $paused = $demands->get(1) ?? CommuteDemand::factory()->create([
            'passenger_user_id' => $tester->id,
            'origin_point' => new Coordinate($village['lat'], $village['lng']),
            'destination_point' => new Coordinate($rehab['lat'], $rehab['lng']),
            'origin_lat' => $village['lat'],
            'origin_lng' => $village['lng'],
            'dest_lat' => $rehab['lat'],
            'dest_lng' => $rehab['lng'],
            'origin_label' => $village['label'],
            'destination_label' => $rehab['label'],
            'preferred_arrival_start' => '18:00:00',
            'preferred_arrival_end' => '19:00:00',
            'expires_at' => now()->addDays(30),
        ]);

        $paused->forceFill(['status' => CommuteDemandStatus::Paused->value])->save();

        if (! $demands->isEmpty()) {
            app(MatchDemandToExistingCommutesAction::class)->execute($demands->first()->load('passenger'));
        }

        foreach ([[$rehab, $village, 1], [$village, $rehab, 3], [$rehab, ['lat' => 30.0444, 'lng' => 31.2357, 'label' => 'Downtown Cairo'], 26]] as [$from, $to, $hoursAgo]) {
            $signature = hash('sha256', "tester-search:{$tester->id}:{$from['label']}:{$to['label']}");

            if (SearchHistory::query()->where('user_id', $tester->id)->where('signature', $signature)->exists()) {
                continue;
            }

            (new SearchHistory)->forceFill([
                'id' => (string) Str::ulid(),
                'user_id' => $tester->id,
                'criteria' => [
                    'origin' => [...$from, 'placeId' => null],
                    'destination' => [...$to, 'placeId' => null],
                    'daysMask' => DaysMask::weekdaysSunToThu()->value,
                    'arrivalWindowStart' => '07:30:00',
                    'arrivalWindowEnd' => '08:30:00',
                ],
                'signature' => $signature,
                'searched_at' => now()->subHours($hoursAgo),
            ])->save();
        }
    }

    private function notify(User $tester): void
    {
        if (Notification::query()->where('user_id', $tester->id)->where('type', NotificationType::SeatApproved->value)->exists()) {
            return;
        }

        $booking = Booking::query()->where('passenger_user_id', $tester->id)->where('status', BookingStatus::Confirmed->value)->with('scheduledTrip')->first();

        // `quiet`: an inbox row for the screen, not a push to somebody's phone during a seed.
        Notifier::send($tester, NotificationType::SeatApproved, ['name' => 'منى'], ['bookingId' => (string) $booking?->id], quiet: true);
        Notifier::send($tester, NotificationType::MatchFound, data: ['commuteId' => (string) $booking?->scheduledTrip?->commute_offer_id], quiet: true);
        Notifier::send($tester, NotificationType::RatingDue, ['name' => 'منى'], ['bookingId' => (string) $booking?->id], quiet: true);
    }

    /**
     * A commission from an earlier week would go overdue and stop a tester publishing — and, a
     * Friday later, suspend the driving side. Nobody testing a screen asked for that, so whatever is
     * older than this week is paid off, and a suspension that came from it lifted.
     */
    private function payOffEarlierWeeks(User $tester): void
    {
        $overdue = DebtStanding::of($tester, DebtSide::Driver)->overduePiastres;

        if ($overdue > 0) {
            DebtLedger::credit($tester, DebtEntryType::Payment, $overdue, 'demo_refresh', side: DebtSide::Driver);
        }

        DebtAccount::query()
            ->where('user_id', $tester->id)
            ->where('side', DebtSide::Driver->value)
            ->whereNotNull('suspended_at')
            ->update(['suspended_at' => null]);
    }

    /*
    |--------------------------------------------------------------------------
    | Shared pieces
    |--------------------------------------------------------------------------
    */

    /**
     * @return list<User>
     */
    private function ridersFor(int $block): array
    {
        $riders = [];

        foreach (self::RIDER_NAMES as $index => $first) {
            $rider = $this->person('+2010555580'.str_pad((string) ($block + $index), 2, '0', STR_PAD_LEFT), $first, [Capability::Passenger]);

            if (! UserStat::query()->where('user_id', $rider->id)->exists()) {
                (new UserStat)->forceFill([
                    'user_id' => $rider->id,
                    'avg_rating_as_passenger' => 4.5 + ($index % 5) / 10,
                    'rating_count_as_passenger' => 8 + $index,
                    'completed_trips_as_passenger' => 12 + $index * 3,
                ])->save();
            }

            $riders[] = $rider;
        }

        return $riders;
    }

    private function demoCommute(string $phone): ?CommuteOffer
    {
        return CommuteOffer::query()
            ->whereHas('driverProfile.user', fn ($user) => $user->where('phone_e164', $phone))
            ->where('status', CommuteOfferStatus::Published->value)
            ->with('driverProfile.user')
            ->orderBy('id')
            ->first();
    }

    /**
     * @return Collection<int, ScheduledTrip> the next bookable days, soonest first, re-indexed
     */
    private function upcoming(CommuteOffer $offer, int $limit = self::DAYS_AHEAD): Collection
    {
        return ScheduledTrip::query()
            ->where('commute_offer_id', $offer->id)
            ->where('status', ScheduledTripStatus::Scheduled->value)
            ->where('departure_at', '>', now())
            ->orderBy('departure_at')
            ->limit($limit)
            ->with('commuteOffer')
            ->get()
            ->values();
    }

    private function groupOf(CommuteOffer $offer): CommuteGroup
    {
        $group = CommuteGroup::query()->where('commute_offer_id', $offer->id)->first();

        if ($group === null) {
            $offer->loadMissing('driverProfile.user');

            $group = new CommuteGroup;
            $group->forceFill([
                'commute_offer_id' => $offer->id,
                'name' => 'مجموعة '.$offer->driverProfile->user->public_first_name,
                'status' => CommuteGroupStatus::Active->value,
                'min_commitment_days_per_week' => 2,
                'on_time_pct' => 96.00,
                'rides_together_count' => 18,
                'seats_open' => 1,
            ])->save();

            $this->member($group, $offer->driverProfile->user, GroupMemberRole::Driver, null);
        }

        return $group;
    }

    private function member(CommuteGroup $group, User $user, GroupMemberRole $role, ?int $daysMask): void
    {
        $exists = GroupMember::query()->where('commute_group_id', $group->id)->where('user_id', $user->id)->exists();

        if (! $exists) {
            $member = new GroupMember;
            $member->forceFill([
                'commute_group_id' => $group->id,
                'user_id' => $user->id,
                'role' => $role->value,
                'status' => GroupMemberStatus::Active->value,
                'committed_days_mask' => $daysMask,
                'joined_at' => now()->subDays(20),
            ])->save();
        }
    }

    /**
     * Her one request on this commute: the one already there, or a new one between the commute's
     * two ends. One per person per commute, as the database's own key holds for an open request.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function ask(CommuteOffer $offer, User $rider, array $overrides = []): SeatRequest
    {
        $existing = SeatRequest::query()
            ->where('commute_offer_id', $offer->id)
            ->where('passenger_user_id', $rider->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $offer->loadMissing('locations');
        $origin = $offer->locations->firstWhere('type.value', 'origin');
        $destination = $offer->locations->firstWhere('type.value', 'destination');

        return SeatRequest::factory()->create([
            'passenger_user_id' => $rider->id,
            'commute_offer_id' => $offer->id,
            'requested_pickup_point' => new Coordinate((float) $origin->lat, (float) $origin->lng),
            'requested_pickup_lat' => $origin->lat,
            'requested_pickup_lng' => $origin->lng,
            'requested_pickup_label' => $origin->address,
            'requested_dropoff_point' => new Coordinate((float) $destination->lat, (float) $destination->lng),
            'requested_dropoff_lat' => $destination->lat,
            'requested_dropoff_lng' => $destination->lng,
            'requested_dropoff_label' => $destination->address,
            ...$overrides,
        ]);
    }

    private function counteroffered(CommuteOffer $offer, User $rider, User $driver): SeatRequest
    {
        $request = $this->ask($offer, $rider, ['commitment' => SeatRequestCommitment::Recurring, 'requested_days_mask' => DaysMask::weekdaysSunToThu()->value]);

        if ($request->status === SeatRequestStatus::Pending) {
            $lat = (float) $request->requested_pickup_lat + 0.003;
            $lng = (float) $request->requested_pickup_lng + 0.003;

            $request->forceFill([
                'status' => SeatRequestStatus::Counteroffered->value,
                'responded_by' => $driver->id,
                'responded_at' => now()->subHours(3),
                'counteroffered_at' => now()->subHours(3),
                'counteroffered_by' => $driver->id,
                'counteroffer_note' => 'أقدر أقابلك عند البوابة الرئيسية بدل النقطة دي.',
                'counteroffer_pickup_point' => new Coordinate($lat, $lng),
                'counteroffer_pickup_lat' => $lat,
                'counteroffer_pickup_lng' => $lng,
                'counteroffer_pickup_label' => 'البوابة الرئيسية',
            ])->save();
        }

        return $request;
    }

    /**
     * A confirmed seat on a day ahead. Leaves it alone when she already holds one, the day is full,
     * or it would overlap another seat of hers: the same promises the real booking keeps (R-3).
     */
    private function seat(User $rider, ScheduledTrip $day, CommuteGroup $group, SeatRequest $request): void
    {
        $taken = Booking::query()
            ->where('scheduled_trip_id', $day->id)
            ->where('passenger_user_id', $rider->id)
            ->exists();

        if ($taken || $day->seats_taken >= $day->seats_total || SameTimeBookings::overlapping($day, (string) $rider->id)->isNotEmpty()) {
            return;
        }

        $this->booking($rider, $day, $group, $request, BookingStatus::Confirmed);

        $day->increment('seats_taken');
    }

    private function pastSeat(User $rider, ScheduledTrip $day, CommuteGroup $group, ?SeatRequest $request, BookingStatus $status): Booking
    {
        return $this->booking($rider, $day, $group, $request, $status);
    }

    private function booking(User $rider, ScheduledTrip $day, CommuteGroup $group, ?SeatRequest $request, BookingStatus $status): Booking
    {
        $offer = $day->loadMissing('commuteOffer')->commuteOffer;

        $booking = new Booking;
        $booking->forceFill([
            'scheduled_trip_id' => $day->id,
            'passenger_user_id' => $rider->id,
            'driver_profile_id' => $offer->driver_profile_id,
            'commute_group_id' => $group->id,
            'seat_request_id' => $request?->id,
            'seats_reserved' => 1,
            'price_snapshot_piastres' => $day->seat_price_piastres,
            'platform_fee_snapshot_piastres' => $day->commission_piastres,
            'driver_amount_snapshot_piastres' => $day->driver_share_piastres,
            'payment_type' => PaymentType::Cash->value,
            'payment_status' => PaymentStatus::NotDue->value,
            'status' => $status->value,
            'cancelled_at' => in_array($status, [BookingStatus::CancelledByDriver, BookingStatus::CancelledByPassenger], true) ? $day->departure_at : null,
            // Already seen, so Home leads with her next journey; History still lists every one (HOME-1).
            'outcome_seen_at' => $status === BookingStatus::Confirmed ? null : now(),
        ]);

        if ($request !== null) {
            $booking->forceFill([
                'pickup_point' => new Coordinate((float) $request->requested_pickup_lat, (float) $request->requested_pickup_lng),
                'pickup_label' => $request->requested_pickup_label,
                'pickup_lat' => $request->requested_pickup_lat,
                'pickup_lng' => $request->requested_pickup_lng,
                'dropoff_point' => new Coordinate((float) $request->requested_dropoff_lat, (float) $request->requested_dropoff_lng),
                'dropoff_label' => $request->requested_dropoff_label,
                'dropoff_lat' => $request->requested_dropoff_lat,
                'dropoff_lng' => $request->requested_dropoff_lng,
            ]);
        }

        $booking->save();

        return $booking->refresh();
    }

    /**
     * A day this commute already ran, with its finished session: the shape the real run leaves.
     */
    private function pastDay(CommuteOffer $offer, int $daysAgo): ScheduledTrip
    {
        $offer->loadMissing('schedule', 'vehicle');
        $schedule = $offer->schedule;
        $vehicle = $offer->vehicle;
        $date = now()->subDays($daysAgo)->toDateString();

        $trip = ScheduledTrip::query()->where('commute_offer_id', $offer->id)->where('trip_date', $date)->first();

        if ($trip === null) {
            $trip = new ScheduledTrip;
            $trip->forceFill([
                'commute_offer_id' => $offer->id,
                'commute_schedule_id' => $schedule->id,
                'trip_date' => $date,
                'departure_at' => DepartureTimeCalculator::toUtc($date, $schedule->departure_time, $schedule->timezone),
                'departure_local' => "{$date} {$schedule->departure_time}",
                'seats_total' => $offer->seats_total,
                'seats_taken' => 2,
                ...SeatPricing::quote((int) $offer->route_distance_meters, $vehicle)->toTripColumns(),
            ]);
        }

        $trip->forceFill(['status' => ScheduledTripStatus::Completed->value])->save();

        if (! TripSession::query()->where('scheduled_trip_id', $trip->id)->exists()) {
            TripSession::create([
                'scheduled_trip_id' => $trip->id,
                'current_status' => TripSessionStatus::Completed,
                'started_at' => $trip->departure_at,
                'completed_at' => $trip->departure_at->copy()->addSeconds((int) $offer->route_duration_seconds),
            ]);
        }

        return $trip->load('commuteOffer');
    }
}
