<?php

namespace App\Http\Controllers\Api\V1\Testing;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\CancelCode;
use App\Domains\Booking\Enums\PaymentStatus;
use App\Domains\Booking\Enums\PaymentType;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Enums\CommuteDirection;
use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\CommuteType;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteSchedule;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Enums\VehicleTier;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Enums\Gender;
use App\Domains\Identity\Enums\ProfileStatus;
use App\Domains\Identity\Enums\RegisteredRole;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Actions\SuspendForDebtAction;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Payment\Models\FeeAppeal;
use App\Domains\Payment\Support\DebtLedger;
use App\Domains\Payment\Support\DebtStanding;
use App\Domains\Payment\Support\FeeLines;
use App\Domains\Payment\Support\SettlementWeek;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\Support\TestAccounts;
use App\Domains\Shared\Testing\LocationFreeze;
use App\Domains\Shared\Testing\TestingFaults;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Trip\Enums\TripSessionStatus;
use App\Domains\Trip\Models\TripSession;
use App\Domains\Trip\Support\LiveLocationStore;
use App\Domains\Trip\Support\TripSettings;
use App\Domains\Trip\ValueObjects\TripPosition;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Resources\BookingResource;
use App\Http\Resources\DebtSideResource;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class TestingDataController extends Controller
{
    public const array KEEPABLE = ['verification', 'vehicles', 'contacts', 'notifications', 'debt', 'reliability'];

    public const array HISTORY_ENDINGS = [
        'COMPLETED' => BookingStatus::Completed,
        'CANCELLED_BY_PASSENGER' => BookingStatus::CancelledByPassenger,
        'CANCELLED_BY_DRIVER' => BookingStatus::CancelledByDriver,
        'NO_SHOW' => BookingStatus::NoShow,
        'EXPIRED' => BookingStatus::Expired,
        'ENDED_EARLY' => BookingStatus::EndedEarly,
        'EXITED_DUE_TO_DRIVER_DELAY' => BookingStatus::ExitedDueToDriverDelay,
    ];

    private const array CANCELLED = [
        BookingStatus::CancelledByPassenger,
        BookingStatus::CancelledByDriver,
    ];

    #[ApiErrors(ErrorCode::ValidationFailed, ErrorCode::TestTripInProgress, ErrorCode::NotFound)]
    public function reset(Request $request): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $data = $request->validate([
            'keep' => ['sometimes', 'array'],
            'keep.*' => ['string', Rule::in(self::KEEPABLE)],
        ]);

        $keep = array_values(array_unique($data['keep'] ?? ['verification']));
        $kept = fn (string $what): bool => in_array($what, $keep, true);

        $offerIds = CommuteOffer::query()->where('driver_profile_id', $user->id)->pluck('id');
        $tripIds = ScheduledTrip::query()->whereIn('commute_offer_id', $offerIds)->pluck('id');

        $bookings = Booking::query()
            ->where(fn ($q) => $q
                ->where('passenger_user_id', $user->id)
                ->orWhere('driver_profile_id', $user->id)
                ->orWhereIn('scheduled_trip_id', $tripIds))
            ->get(['id', 'scheduled_trip_id', 'passenger_user_id', 'driver_profile_id', 'status', 'seats_reserved']);

        $this->refuseIfRealMemberAboard($user, $bookings);

        $bookingIds = $bookings->pluck('id');

        $seatRequestIds = SeatRequest::query()
            ->where(fn ($q) => $q->where('passenger_user_id', $user->id)->orWhereIn('commute_offer_id', $offerIds))
            ->pluck('id');

        $counts = DB::transaction(function () use ($user, $offerIds, $tripIds, $bookings, $bookingIds, $seatRequestIds, $kept): array {
            $counts = [
                'commutes' => $offerIds->count(),
                'trips' => $tripIds->count(),
                'seatRequests' => SeatRequest::query()->whereKey($seatRequestIds)->where('status', '!=', SeatRequestStatus::Waitlisted->value)->count(),
                'bookings' => $bookings->count(),
                'waitlist' => SeatRequest::query()->whereKey($seatRequestIds)->where('status', SeatRequestStatus::Waitlisted->value)->count(),
                'offers' => DB::table('seat_offers')->where(fn ($q) => $q->whereIn('seat_request_id', $seatRequestIds)->orWhereIn('scheduled_trip_id', $tripIds))->count(),
                'demands' => DB::table('commute_demands')->where('passenger_user_id', $user->id)->count(),
                'proposals' => DB::table('change_proposals')->where(fn ($q) => $q->whereIn('commute_offer_id', $offerIds)->orWhereIn('scheduled_trip_id', $tripIds))->count(),
                'pickupChanges' => DB::table('pickup_point_requests')->where(fn ($q) => $q->where('requested_by_user_id', $user->id)->orWhereIn('seat_request_id', $seatRequestIds))->count(),
                'groups' => DB::table('commute_groups')->whereIn('commute_offer_id', $offerIds)->count() + DB::table('group_members')->where('user_id', $user->id)->count(),
                'vehicles' => 0,
                'contacts' => 0,
                'liveShares' => DB::table('live_shares')->where('user_id', $user->id)->count(),
                'incidents' => DB::table('incidents')->where('reporter_user_id', $user->id)->count(),
                'conversations' => 0,
                'notifications' => 0,
                'debtLines' => 0,
                'reliabilityEvents' => 0,
                'warnings' => 0,
                'faults' => TestingFaults::clear($user->id),
            ];

            $sessionIds = DB::table('trip_sessions')->whereIn('scheduled_trip_id', $tripIds)->pluck('id');

            $this->releaseSeatsOnOtherTrips($bookings, $tripIds->all());

            $counts['conversations'] = DB::table('conversations')
                ->where(fn ($q) => $q->whereIn('booking_id', $bookingIds)->orWhereIn('seat_request_id', $seatRequestIds))
                ->delete();

            DB::table('payments')->whereIn('booking_id', $bookingIds)->delete();
            DB::table('ratings')->whereIn('booking_id', $bookingIds)->delete();
            DB::table('trip_sessions')->whereIn('id', $sessionIds)->delete();
            DB::table('bookings')->whereIn('id', $bookingIds)->delete();
            DB::table('seat_requests')->whereIn('id', $seatRequestIds)->delete();
            DB::table('group_members')->where('user_id', $user->id)->delete();
            DB::table('commute_groups')->whereIn('commute_offer_id', $offerIds)->delete();
            DB::table('scheduled_trips')->whereIn('id', $tripIds)->delete();
            DB::table('commute_offers')->whereIn('id', $offerIds)->delete();
            DB::table('commute_demands')->where('passenger_user_id', $user->id)->delete();
            DB::table('pickup_point_requests')->where('requested_by_user_id', $user->id)->delete();
            DB::table('live_shares')->where('user_id', $user->id)->delete();
            DB::table('incidents')->where('reporter_user_id', $user->id)->delete();

            if (! $kept('vehicles')) {
                $counts['vehicles'] = DB::table('vehicles')->where('driver_profile_id', $user->id)->where('is_active', false)->delete();
            }

            if (! $kept('contacts')) {
                $counts['contacts'] = DB::table('emergency_contacts')->where('user_id', $user->id)->delete();
            }

            if (! $kept('notifications')) {
                $counts['notifications'] = DB::table('notifications')->where('user_id', $user->id)->delete();
            }

            if (! $kept('reliability')) {
                $counts['reliabilityEvents'] = DB::table('reliability_events')->where('user_id', $user->id)->delete();
                $counts['warnings'] = DB::table('member_warnings')->where('user_id', $user->id)->delete();
            }

            if (! $kept('debt')) {
                $counts['debtLines'] = $this->removeDebt($user->id);
            }

            if (! $kept('verification')) {
                $verificationIds = DB::table('user_verifications')->where('user_id', $user->id)->pluck('id');
                DB::table('identity_documents')->whereIn('user_verification_id', $verificationIds)->delete();
                DB::table('user_verifications')->whereIn('id', $verificationIds)->delete();
            }

            return $counts;
        });

        foreach ([DebtSide::Driver, DebtSide::Passenger] as $side) {
            app(SuspendForDebtAction::class)->liftIfPaid($user->id, $side);
        }

        $keptNames = [];

        foreach (self::KEEPABLE as $name) {
            if ($kept($name)) {
                $keptNames[] = (string) $name;
            }
        }

        return ApiResponse::success([...$counts, 'kept' => $keptNames]);
    }

    #[ApiErrors(ErrorCode::ValidationFailed, ErrorCode::NotFound)]
    public function seedHistory(Request $request): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $data = $request->validate([
            'count' => ['required', 'integer', 'between:1,60'],
            'ending' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(self::HISTORY_ENDINGS))],
        ]);

        $count = (int) $data['count'];
        $endings = array_values(self::HISTORY_ENDINGS);

        $ids = DB::transaction(function () use ($user, $count, $data, $endings): array {
            $driver = $this->historyDriver();
            $vehicle = $driver->driverProfile->vehicles()->where('is_active', true)->firstOrFail();
            [$offer, $schedule] = $this->hiddenCommute($driver, $vehicle, $count);

            $ids = [];

            for ($i = 0; $i < $count; $i++) {
                $status = isset($data['ending']) && $data['ending'] !== null
                    ? self::HISTORY_ENDINGS[$data['ending']]
                    : $endings[$i % count($endings)];

                $ids[] = $this->pastBooking($user, $driver, $offer, $schedule, $i + 1, $status);
            }

            return $ids;
        });

        return ApiResponse::success(['bookingIds' => $ids, 'count' => count($ids)], status: 201);
    }

    #[ApiErrors(ErrorCode::ValidationFailed, ErrorCode::RequestStillPending, ErrorCode::NotFound)]
    public function ageSeatRequest(Request $request, string $seatRequest): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());
        $days = (int) $request->validate(['days' => ['required', 'integer', 'between:1,60']])['days'];

        $found = SeatRequest::query()->whereKey($seatRequest)->where('passenger_user_id', $user->id)->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        if ($found->status === SeatRequestStatus::Pending
            && ($found->expires_at === null || CarbonImmutable::instance($found->expires_at)->subDays($days)->isFuture())) {
            throw DomainException::of(ErrorCode::RequestStillPending, fields: [
                'expiresAt' => [$found->expires_at?->toIso8601String()],
            ]);
        }

        $sets = [];

        foreach (['created_at', 'updated_at', 'responded_at', 'expires_at', 'displaced_at', 'counteroffered_at', 'counteroffer_answered_at', 'paused_at', 'agreed_to_rules_at'] as $column) {
            $sets[$column] = DB::raw("DATE_SUB(`{$column}`, INTERVAL {$days} DAY)");
        }

        DB::table('seat_requests')->where('id', $found->id)->update($sets);

        if ($found->status === SeatRequestStatus::Pending) {
            Artisan::call('requests:expire');
        }

        $found->refresh();

        return ApiResponse::success([
            'id' => $found->id,
            'status' => strtoupper($found->status->value),
            'createdAt' => $found->created_at?->toIso8601String(),
            'decidedAt' => $found->responded_at?->toIso8601String(),
            'expiresAt' => $found->expires_at?->toIso8601String(),
        ]);
    }

    #[ApiErrors(ErrorCode::ValidationFailed, ErrorCode::DriverNotApproved, ErrorCode::NotFound)]
    public function seedDebt(Request $request): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $data = $request->validate([
            'side' => ['required', 'string', Rule::in(['driver', 'passenger'])],
            'kind' => ['required', 'string', Rule::in(['commission', 'no_show_fee', 'late_cancel_fee', 'late_fee'])],
            'amountPiastres' => ['required', 'integer', 'between:100,500000'],
            'count' => ['sometimes', 'integer', 'between:1,10'],
            'bookingId' => ['sometimes', 'nullable', 'string', 'size:26'],
            'disputed' => ['sometimes', 'boolean'],
        ]);

        $side = DebtSide::from($data['side']);

        if ($side === DebtSide::Driver && ! DriverProfile::query()->whereKey($user->id)->where('status', DriverProfileStatus::Approved->value)->exists()) {
            throw DomainException::of(ErrorCode::DriverNotApproved);
        }

        [$type, $reason] = match ([$side, $data['kind']]) {
            [DebtSide::Driver, 'commission'] => [DebtEntryType::Commission, 'seat_commission'],
            [DebtSide::Driver, 'no_show_fee'] => [DebtEntryType::Fee, 'driver_no_show'],
            [DebtSide::Driver, 'late_cancel_fee'] => [DebtEntryType::Fee, 'driver_cancel'],
            [DebtSide::Driver, 'late_fee'] => [DebtEntryType::LateFee, 'paid_after_friday'],
            [DebtSide::Passenger, 'no_show_fee'] => [DebtEntryType::Fee, 'no_show'],
            [DebtSide::Passenger, 'late_cancel_fee'] => [DebtEntryType::Fee, 'late_cancel'],
            [DebtSide::Passenger, 'late_fee'] => [DebtEntryType::Fee, 'late_boarding'],
            default => throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                'kind' => ['A commission is only ever on the driver side.'],
            ]),
        };

        $booking = null;

        if (! empty($data['bookingId'])) {
            $booking = Booking::query()
                ->whereKey($data['bookingId'])
                ->where($side === DebtSide::Driver ? 'driver_profile_id' : 'passenger_user_id', $user->id)
                ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Pending->value])
                ->whereHas('scheduledTrip', fn ($q) => $q->where('departure_at', '>', now()))
                ->first()
                ?? throw DomainException::of(ErrorCode::NotFound);
        }

        $count = (int) ($data['count'] ?? 1);
        $disputed = (bool) ($data['disputed'] ?? false);
        $lineIds = [];

        for ($i = $count - 1; $i >= 0; $i--) {
            $at = CarbonImmutable::now()->subDays($i);
            $key = 'test_seed:'.Str::ulid();

            $line = DB::transaction(function () use ($user, $side, $type, $reason, $data, $booking, $at, $key, $disputed): DebtEntry {
                if ($disputed) {
                    DebtLedger::lockedAccount($user->id, $side);
                }

                $line = DebtLedger::record(
                    $user->id,
                    $side,
                    $type,
                    (int) $data['amountPiastres'],
                    $reason,
                    bookingId: $booking?->id,
                    meta: ['rule' => 'TEST'],
                    idempotencyKey: $key,
                    weekOf: SettlementWeek::weekOf($at),
                );

                DB::table('debt_entries')->where('id', $line->id)->update(['created_at' => $at]);

                if ($disputed) {
                    $appeal = new FeeAppeal;
                    $appeal->debt_entry_id = $line->id;
                    $appeal->user_id = $user->id;
                    $appeal->reason = 'Disputed by the test run.';
                    $appeal->save();
                }

                return $line;
            });

            $lineIds[] = $line->id;
        }

        if ($side === DebtSide::Passenger) {
            FeeLines::refresh($user->id);
        }

        return ApiResponse::success([
            'lineIds' => $lineIds,
            'side' => $side->value,
            ...(new DebtSideResource(DebtStanding::of($user, $side)))->toArray($request),
        ], status: 201);
    }

    #[ApiErrors(ErrorCode::ValidationFailed, ErrorCode::NotFound)]
    public function credit(Request $request): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $data = $request->validate([
            'amountPiastres' => ['required', 'integer', 'between:100,500000'],
            'reason' => ['sometimes', 'string', 'regex:/^[a-z][a-z0-9_]{1,39}$/'],
            'side' => ['sometimes', 'string', Rule::in(['driver', 'passenger'])],
        ]);

        $side = DebtSide::from($data['side'] ?? 'passenger');

        $line = DebtLedger::credit(
            $user,
            DebtEntryType::Credit,
            (int) $data['amountPiastres'],
            $data['reason'] ?? 'driver_cancelled',
            meta: ['rule' => 'TEST'],
            side: $side,
            idempotencyKey: 'test_credit:'.Str::ulid(),
        );

        if ($side === DebtSide::Passenger) {
            FeeLines::refresh($user->id);
        }

        $balance = DebtLedger::balanceOf($user, $side);

        return ApiResponse::success([
            'creditId' => $line->id,
            'side' => $side->value,
            'creditPiastres' => (int) max(0, -$balance),
            'balancePiastres' => $balance,
        ], status: 201);
    }

    #[ApiErrors(ErrorCode::ValidationFailed, ErrorCode::BookingNotCancelled, ErrorCode::NotFound)]
    public function bookingFields(Request $request, string $booking): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $data = $request->validate([
            'paymentStatus' => ['sometimes', 'string', Rule::in(array_map(fn (PaymentStatus $s): string => strtoupper($s->value), PaymentStatus::cases()))],
            'cancellationReason' => ['sometimes', 'string', Rule::in(array_map(fn (CancelCode $c): string => $c->value, CancelCode::cases()))],
            'cancelledNote' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pickupLabel' => ['sometimes', 'nullable', 'string', 'max:120'],
            'dropoffLabel' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $hers = Booking::query()->whereKey($booking)->where('passenger_user_id', $user->id)->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        if ((array_key_exists('cancellationReason', $data) || array_key_exists('cancelledNote', $data)) && ! in_array($hers->status, self::CANCELLED, true)) {
            throw DomainException::of(ErrorCode::BookingNotCancelled, fields: [
                'status' => [strtoupper($hers->status->value)],
            ]);
        }

        $sets = [];

        if (array_key_exists('paymentStatus', $data)) {
            $sets['payment_status'] = strtolower($data['paymentStatus']);
        }

        if (array_key_exists('cancellationReason', $data)) {
            $sets['cancel_code'] = $data['cancellationReason'];
        }

        if (array_key_exists('cancelledNote', $data)) {
            $sets['cancelled_reason'] = $data['cancelledNote'];
        }

        if (array_key_exists('pickupLabel', $data)) {
            $sets['pickup_label'] = $data['pickupLabel'];
        }

        if (array_key_exists('dropoffLabel', $data)) {
            $sets['dropoff_label'] = $data['dropoffLabel'];
        }

        if ($sets !== []) {
            DB::table('bookings')->where('id', $hers->id)->update([...$sets, 'updated_at' => now()]);
        }

        $fresh = Booking::query()->whereKey($hers->id)->firstOrFail()->load([
            'scheduledTrip',
            'scheduledTrip.commuteOffer.vehicle',
            ...BookingResource::historyRelations($user),
            ...BookingResource::pickupEtaRelations(),
        ]);

        return ApiResponse::success(new BookingResource($fresh));
    }

    #[ApiErrors(ErrorCode::ValidationFailed, ErrorCode::TripNotStarted, ErrorCode::NotFound)]
    public function ageLocation(Request $request, string $trip, LiveLocationStore $store): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());
        $minutes = (int) $request->validate(['minutes' => ['required', 'integer', 'between:1,30']])['minutes'];

        $occurrence = ScheduledTrip::query()
            ->whereKey($trip)
            ->where(fn ($q) => $q
                ->whereIn('commute_offer_id', CommuteOffer::query()->where('driver_profile_id', $user->id)->select('id'))
                ->orWhereIn('id', Booking::query()
                    ->where('passenger_user_id', $user->id)
                    ->where('status', BookingStatus::Confirmed->value)
                    ->select('scheduled_trip_id')))
            ->with('tripSession')
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        $session = $occurrence->tripSession;

        if ($session === null || ! $session->current_status->isUnderway()) {
            throw DomainException::of(ErrorCode::TripNotStarted, fields: [
                'tripStatus' => [strtoupper($session?->current_status->value ?? ScheduledTripStatus::Scheduled->value)],
            ]);
        }

        $at = CarbonImmutable::now()->subMinutes($minutes)->startOfSecond();

        LocationFreeze::hold($session->id);

        $current = $store->current($session->id);

        if ($current !== null) {
            $store->putCurrent($session->id, new TripPosition(
                lat: $current->lat,
                lng: $current->lng,
                recordedAt: $at,
                accuracyMeters: $current->accuracyMeters,
                speedKmh: $current->speedKmh,
            ));
        }

        $store->forgetPending($session->id);

        TripSession::query()->whereKey($session->id)->update(['last_location_at' => $at]);

        $ageSeconds = (int) $at->diffInSeconds(CarbonImmutable::now(), true);

        return ApiResponse::success([
            'tripId' => $occurrence->id,
            'recordedAt' => $at->toIso8601String(),
            'ageSeconds' => $ageSeconds,
            'isStale' => $ageSeconds >= TripSettings::gpsSilenceAlertSeconds(),
            'ignoringNewPointsUntil' => CarbonImmutable::now()->addSeconds(60)->toIso8601String(),
        ]);
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     */
    private function refuseIfRealMemberAboard(User $user, Collection $bookings): void
    {
        $underway = TripSession::query()
            ->whereIn('scheduled_trip_id', $bookings->pluck('scheduled_trip_id')->unique())
            ->get()
            ->filter(fn (TripSession $session): bool => $session->current_status->isUnderway())
            ->pluck('scheduled_trip_id')
            ->all();

        foreach ($bookings as $booking) {
            if (! in_array($booking->scheduled_trip_id, $underway, true) || $booking->status !== BookingStatus::Confirmed) {
                continue;
            }

            $other = $booking->passenger_user_id === $user->id ? $booking->driver_profile_id : $booking->passenger_user_id;

            if ($other !== $user->id && ! TestAccounts::isTestAccount($other)) {
                throw DomainException::of(ErrorCode::TestTripInProgress, fields: [
                    'tripId' => [(string) $booking->scheduled_trip_id],
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @param  list<string>  $ownTripIds
     */
    private function releaseSeatsOnOtherTrips(Collection $bookings, array $ownTripIds): void
    {
        $bookings
            ->filter(fn (Booking $b): bool => ! in_array($b->scheduled_trip_id, $ownTripIds, true) && $b->status === BookingStatus::Confirmed)
            ->groupBy('scheduled_trip_id')
            ->each(function (Collection $seats, string $tripId): void {
                $release = (int) $seats->sum('seats_reserved');

                DB::table('scheduled_trips')
                    ->where('id', $tripId)
                    ->update(['seats_taken' => DB::raw("GREATEST(0, CAST(`seats_taken` AS SIGNED) - {$release})")]);
            });
    }

    private function removeDebt(string $userId): int
    {
        $entryIds = DB::table('debt_entries')->where('user_id', $userId)->pluck('id');

        DB::table('coupons')
            ->where(fn ($q) => $q->where('user_id', $userId)->orWhereIn('debt_entry_id', $entryIds)->orWhereIn('source_entry_id', $entryIds))
            ->delete();
        DB::table('fee_appeals')->where(fn ($q) => $q->where('user_id', $userId)->orWhereIn('debt_entry_id', $entryIds))->delete();
        DB::table('member_warnings')->whereIn('debt_entry_id', $entryIds)->update(['debt_entry_id' => null]);
        DB::table('debt_entries')->whereIn('reverses_entry_id', $entryIds)->update(['reverses_entry_id' => null]);

        $removed = DB::table('debt_entries')->whereIn('id', $entryIds)->delete();

        DB::table('debt_payments')->where('user_id', $userId)->delete();
        DB::table('debt_accounts')->where('user_id', $userId)->delete();
        DB::table('bookings')->where('passenger_user_id', $userId)->where('fee_line_piastres', '!=', 0)
            ->update(['fee_line_piastres' => 0, 'fee_line_reason' => null]);

        return $removed;
    }

    private function historyDriver(): User
    {
        $phone = (string) config('rafeeq.testing.history_driver_phone', '+201020009999');

        $driver = User::query()->where('phone_e164', $phone)->first();

        if ($driver === null) {
            $driver = new User;
            $driver->forceFill([
                'phone_e164' => $phone,
                'phone_verified_at' => now(),
                'full_name' => 'Test Driver',
                'public_first_name' => 'Mona',
                'gender' => Gender::Woman,
                'account_status' => AccountStatus::Active,
                'profile_status' => ProfileStatus::BasicComplete,
                'registered_role' => RegisteredRole::Driver,
                'preferred_language' => 'en',
            ])->save();
        }

        $profile = DriverProfile::query()->find($driver->id);

        if ($profile === null) {
            $profile = new DriverProfile;
            $profile->forceFill([
                'user_id' => $driver->id,
                'status' => DriverProfileStatus::Approved,
                'verified_at' => now(),
                'national_id' => '2990101'.substr(preg_replace('/\D/', '', $phone), -7),
                'licence_number' => substr(preg_replace('/\D/', '', $phone), -6),
                'licence_expiry' => now()->addYears(3),
            ])->save();
        }

        if (! Vehicle::query()->where('driver_profile_id', $driver->id)->where('is_active', true)->exists()) {
            $plate = 'TST '.substr(preg_replace('/\D/', '', $phone), -4);

            $vehicle = new Vehicle;
            $vehicle->forceFill([
                'driver_profile_id' => $driver->id,
                'make' => 'Hyundai',
                'model' => 'Elantra',
                'year' => 2022,
                'colour' => 'White',
                'plate_number' => $plate,
                'plate_normalized' => Vehicle::normalizePlate($plate),
                'seats' => 4,
                'approved_seats' => 3,
                'tier' => VehicleTier::Standard,
                'has_ac' => true,
                'is_active' => true,
                'verification_status' => VehicleVerificationStatus::Approved,
            ])->save();
        }

        return $driver->load('driverProfile');
    }

    /**
     * @return array{0: CommuteOffer, 1: CommuteSchedule}
     */
    private function hiddenCommute(User $driver, Vehicle $vehicle, int $days): array
    {
        $offer = new CommuteOffer;
        $offer->forceFill([
            'driver_profile_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'commute_type' => CommuteType::Recurring,
            'status' => CommuteOfferStatus::Archived,
            'direction' => CommuteDirection::ToWork,
            'seats_total' => 3,
            'seat_price_piastres' => 4500,
            'driver_share_piastres' => 4000,
            'max_detour_minutes' => 10,
            'max_walk_minutes' => 10,
            'audience' => CommuteAudience::AnyVerified,
            'allows_custom_pickup' => true,
            'route_distance_meters' => 18000,
            'route_duration_seconds' => 1800,
        ])->save();

        foreach ([
            [CommuteLocationType::Origin, 30.0444, 31.2357, 'Tahrir Square', 0],
            [CommuteLocationType::Destination, 30.0131, 31.4089, 'Fifth Settlement', 1],
        ] as [$type, $lat, $lng, $address, $sequence]) {
            $location = new CommuteLocation;
            $location->forceFill([
                'commute_offer_id' => $offer->id,
                'type' => $type,
                'point' => new Coordinate(lat: $lat, lng: $lng),
                'lat' => $lat,
                'lng' => $lng,
                'address' => $address,
                'sequence' => $sequence,
                'is_exact' => true,
            ])->save();
        }

        $schedule = new CommuteSchedule;
        $schedule->forceFill([
            'commute_offer_id' => $offer->id,
            'days_mask' => 127,
            'departure_time' => '07:30:00',
            'timezone' => 'Africa/Cairo',
            'start_date' => CarbonImmutable::today('Africa/Cairo')->subDays($days + 1)->toDateString(),
            'end_date' => CarbonImmutable::today('Africa/Cairo')->subDay()->toDateString(),
        ])->save();

        return [$offer, $schedule];
    }

    private function pastBooking(User $passenger, User $driver, CommuteOffer $offer, CommuteSchedule $schedule, int $daysBack, BookingStatus $status): string
    {
        $date = CarbonImmutable::today('Africa/Cairo')->subDays($daysBack);
        $local = CarbonImmutable::parse($date->toDateString().' 07:30:00', 'Africa/Cairo');
        $departure = $local->utc();

        $tripStatus = match ($status) {
            BookingStatus::Expired => ScheduledTripStatus::Expired,
            default => ScheduledTripStatus::Completed,
        };

        $trip = new ScheduledTrip;
        $trip->forceFill([
            'commute_offer_id' => $offer->id,
            'commute_schedule_id' => $schedule->id,
            'trip_date' => $date->toDateString(),
            'departure_at' => $departure,
            'departure_local' => $local->format('Y-m-d H:i:s'),
            'promised_arrival_at' => $departure->addMinutes(30),
            'seats_total' => 3,
            'seats_taken' => 0,
            'seat_price_piastres' => 4500,
            'commission_piastres' => 500,
            'driver_share_piastres' => 4000,
            'status' => $tripStatus,
        ])->save();

        if ($tripStatus === ScheduledTripStatus::Completed) {
            $session = new TripSession;
            $session->forceFill([
                'scheduled_trip_id' => $trip->id,
                'current_status' => TripSessionStatus::Completed,
                'started_at' => $departure->subMinutes(5),
                'departed_at' => $departure,
                'completed_at' => $departure->addMinutes(32),
            ])->save();
        }

        $cancelled = in_array($status, self::CANCELLED, true);

        $booking = new Booking;
        $booking->forceFill([
            'scheduled_trip_id' => $trip->id,
            'passenger_user_id' => $passenger->id,
            'driver_profile_id' => $driver->id,
            'seats_reserved' => 1,
            'price_snapshot_piastres' => 4500,
            'platform_fee_snapshot_piastres' => 500,
            'driver_amount_snapshot_piastres' => 4000,
            'payment_type' => PaymentType::Cash,
            'payment_status' => match ($status) {
                BookingStatus::Completed, BookingStatus::EndedEarly => PaymentStatus::Paid,
                BookingStatus::ExitedDueToDriverDelay => PaymentStatus::Waived,
                default => PaymentStatus::NotDue,
            },
            'status' => $status,
            'pickup_point' => new Coordinate(lat: 30.0450, lng: 31.2360),
            'pickup_lat' => 30.0450,
            'pickup_lng' => 31.2360,
            'pickup_label' => 'Tahrir Square',
            'dropoff_point' => new Coordinate(lat: 30.0135, lng: 31.4080),
            'dropoff_lat' => 30.0135,
            'dropoff_lng' => 31.4080,
            'dropoff_label' => 'Fifth Settlement',
            'cancelled_at' => $cancelled ? $departure->subHours(3) : null,
            'cancelled_reason' => $cancelled ? 'plans_changed' : null,
            'outcome_seen_at' => now(),
        ])->save();

        DB::table('bookings')->where('id', $booking->id)->update([
            'created_at' => $departure->subDays(2),
            'updated_at' => $departure->addMinutes(32),
        ]);

        return $booking->id;
    }
}
