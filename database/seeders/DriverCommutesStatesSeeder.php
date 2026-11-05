<?php

namespace Database\Seeders;

use App\Domains\Admin\Actions\SuspendMemberAction;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Actions\OfferReplacementAction;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\PointSource;
use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Booking\Enums\SeatRequestCommitment;
use App\Domains\Booking\Enums\SeatRequestKind;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Actions\ChangeCommuteStatusAction;
use App\Domains\Commute\Actions\CreateCommuteOfferAction;
use App\Domains\Commute\Actions\ProposeCommuteChangeAction;
use App\Domains\Commute\Actions\PublishCommuteOfferAction;
use App\Domains\Commute\Actions\RespondToChangeProposalAction;
use App\Domains\Commute\Actions\SaveCommuteRouteAction;
use App\Domains\Commute\Actions\SaveCommuteScheduleAction;
use App\Domains\Commute\Enums\ChangeApprovalResponse;
use App\Domains\Commute\Enums\ChangeProposalScope;
use App\Domains\Commute\Enums\ChangeProposalStatus;
use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Enums\CommutePausedReason;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Enums\TripCancelledBy;
use App\Domains\Commute\Models\ChangeProposal;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Actions\ReviewDriverApplicationAction;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Enums\Capability;
use App\Domains\Identity\Enums\Gender;
use App\Domains\Identity\Enums\SuspensionReason;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtAccount;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Payment\Support\DebtLedger;
use App\Domains\Payment\Support\SettlementWeek;
use App\Domains\Rating\Enums\RatingDirection;
use App\Domains\Rating\Enums\RatingKind;
use App\Domains\Rating\Models\Rating;
use App\Domains\Reliability\Models\ReliabilityEvent;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Trip\Enums\TripSessionStatus;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DriverCommutesStatesSeeder extends DriverHomeStatesSeeder
{
    public const array COMMUTE_STATES = [
        'DC-PASSENGERS' => '+201055557501',
        'DC-MIXED-LIST' => '+201055557502',
        'DC-NOBODY-BOOKED' => '+201055557503',
        'DC-PAUSED-BY-DRIVER' => '+201055557511',
        'DC-PAUSED-VEHICLE' => '+201055557512',
        'DC-PAUSED-LICENCE' => '+201055557513',
        'DC-PAUSED-COMMISSION' => '+201055557514',
        'DC-PAUSED-ACCOUNT' => '+201055557515',
        'DC-PAUSED-DRIVER' => '+201055557516',
        'DC-PAUSED-GENDER' => '+201055557517',
        'DC-PAST-DAYS' => '+201055557521',
        'DC-FEES-NONE' => '+201055557531',
        'DC-FEES-MID' => '+201055557532',
        'DC-FEES-SHORT' => '+201055557533',
        'DC-TRIP-STARTED' => '+201055557534',
        'DC-PROPOSAL-OPEN' => '+201055557541',
        'DC-PROPOSAL-REVISED' => '+201055557542',
        'DC-PROPOSAL-REJECTED' => '+201055557543',
        'DC-PROPOSAL-APPLIED' => '+201055557544',
        'DC-PROPOSAL-EXPIRED' => '+201055557545',
        'DC-ITINERARY' => '+201055557551',
        'DC-OVER-LIMIT' => '+201055557552',
        'DC-WAITLIST' => '+201055557561',
        'DC-OFFERS' => '+201055557562',
        'DC-REPLACE' => '+201055557571',
        'DC-REPLACE-PENDING' => '+201055557572',
        'DC-REPLACE-LOCKED' => '+201055557573',
    ];

    public const array RIDERS = [
        'Salma' => '+201055557601',
        'Nour' => '+201055557602',
        'Hana' => '+201055557603',
        'Mariam' => '+201055557604',
        'Yasmin' => '+201055557605',
        'Farida' => '+201055557606',
        'Layla' => '+201055557607',
        'Dina' => '+201055557608',
        'Reem' => '+201055557609',
        'Rana' => '+201055557610',
        'Aya' => '+201055557611',
    ];

    private const string OTHER_COMMUTE_DRIVER = '+201055557599';

    private const int OUTSIDE_LOCK_MINUTES = 60 * 40;

    private const int MID_NOTICE_MINUTES = 60 * 13;

    private const int SHORT_NOTICE_MINUTES = 60 * 5;

    public function applyCommuteStates(?string $only = null): array
    {
        $report = [];

        foreach (self::COMMUTE_STATES as $state => $phone) {
            if ($only !== null && $only !== $state) {
                continue;
            }

            $this->applyCommuteState($state, $phone);

            $report[] = "{$phone}  {$state}";
        }

        return $report;
    }

    private function applyCommuteState(string $state, string $phone): void
    {
        $driver = $this->commuteDriver($state, $phone);
        $offers = CommuteOffer::query()
            ->where('driver_profile_id', $driver->id)
            ->whereNotNull('published_at')
            ->with('locations', 'schedule')
            ->orderBy('id')
            ->get();

        foreach ($offers as $offer) {
            $this->wipe($driver, $offer);
        }

        $this->clearDebt($driver->id);

        $offer = $offers->first();
        $days = $this->comingDays($offer);

        match ($state) {
            'DC-PASSENGERS' => $this->passengers($offer, $days),
            'DC-MIXED-LIST' => $this->mixedList($driver, $offer, $offers),
            'DC-NOBODY-BOOKED' => null,
            'DC-PAUSED-BY-DRIVER' => $this->paused($offer, $days, CommutePausedReason::ByDriver),
            'DC-PAUSED-VEHICLE' => $this->pausedVehicle($offer, $days),
            'DC-PAUSED-LICENCE' => $this->pausedLicence($driver, $offer, $days),
            'DC-PAUSED-COMMISSION' => $this->pausedCommission($driver, $offer, $days),
            'DC-PAUSED-ACCOUNT' => $this->pausedAccount($driver, $offer, $days),
            'DC-PAUSED-DRIVER' => $this->pausedDriver($driver, $offer, $days),
            'DC-PAUSED-GENDER' => $this->pausedGender($driver, $offer, $days),
            'DC-PAST-DAYS' => $this->pastDays($offer, $days),
            'DC-FEES-NONE' => $this->fees($offer, self::OUTSIDE_LOCK_MINUTES),
            'DC-FEES-MID' => $this->fees($offer, self::MID_NOTICE_MINUTES),
            'DC-FEES-SHORT' => $this->fees($offer, self::SHORT_NOTICE_MINUTES),
            'DC-TRIP-STARTED' => $this->tripStarted($offer),
            'DC-PROPOSAL-OPEN' => $this->proposalOpen($offer),
            'DC-PROPOSAL-REVISED' => $this->proposalRevised($offer),
            'DC-PROPOSAL-REJECTED' => $this->proposalRejected($offer),
            'DC-PROPOSAL-APPLIED' => $this->proposalApplied($offer),
            'DC-PROPOSAL-EXPIRED' => $this->proposalExpired($offer),
            'DC-ITINERARY' => $this->itinerary($offer),
            'DC-OVER-LIMIT' => $this->overLimit($offer),
            'DC-WAITLIST' => $this->waitlist($offer, $days),
            'DC-OFFERS' => $this->offers($offer, $days),
            'DC-REPLACE' => $this->replace($offer, self::OUTSIDE_LOCK_MINUTES),
            'DC-REPLACE-PENDING' => $this->replacePending($offer),
            'DC-REPLACE-LOCKED' => $this->replaceLocked($offer),
            default => null,
        };
    }

    private function wipe(User $driver, CommuteOffer $offer): void
    {
        $driver->forceFill(['gender' => Gender::Woman->value])->save();
        $offer->forceFill(['audience' => CommuteAudience::AnyVerified->value])->save();

        $this->resetDriver($driver->refresh(), $offer);

        $requests = SeatRequest::query()->where('commute_offer_id', $offer->id)->pluck('id');
        $trips = ScheduledTrip::query()->where('commute_offer_id', $offer->id)->pluck('id');
        $bookings = Booking::query()->whereIn('scheduled_trip_id', $trips)->pluck('id');

        ChangeProposal::query()->where('commute_offer_id', $offer->id)->delete();
        SeatOffer::query()->whereIn('scheduled_trip_id', $trips)->delete();
        TripSession::query()->whereIn('scheduled_trip_id', $trips)->delete();
        Rating::withTrashed()->whereIn('booking_id', $bookings)->forceDelete();
        ReliabilityEvent::query()->where('commute_offer_id', $offer->id)->delete();
        DB::table('occurrence_navigation_stops')->whereIn('scheduled_trip_id', $trips)->delete();

        foreach ($bookings as $id) {
            try {
                Booking::query()->whereKey($id)->delete();
            } catch (QueryException) {
                continue;
            }
        }

        foreach ($requests as $id) {
            try {
                SeatRequest::query()->whereKey($id)->delete();
            } catch (QueryException) {
                continue;
            }
        }

        ScheduledTrip::query()->where('commute_offer_id', $offer->id)
            ->where('trip_date', '<', now($offer->schedule->timezone)->toDateString())
            ->delete();

        ScheduledTrip::query()->where('commute_offer_id', $offer->id)->update([
            'status' => ScheduledTripStatus::Scheduled->value,
            'cancelled_reason' => null,
            'cancelled_by' => null,
            'seats_taken' => 0,
            'seats_total' => 3,
            'expired_at' => null,
        ]);

        $offer->weekdaySchedules()->update(['promised_arrival_time' => '09:00:00', 'departure_is_computed' => true, 'seats_total' => null]);

        $this->fixUpcomingTimes($offer);

        $offer->forceFill(['seats_total' => 3])->save();
    }

    private function fixUpcomingTimes(CommuteOffer $offer): void
    {
        $timezone = $offer->schedule->timezone;
        $today = now($timezone)->toDateString();
        $duration = (int) $offer->route_duration_seconds;

        foreach (ScheduledTrip::query()->where('commute_offer_id', $offer->id)->where('trip_date', '>=', $today)->get() as $day) {
            $arrival = now($timezone)->setDateFrom($day->trip_date)->setTime(9, 0);
            $departure = $arrival->copy()->subSeconds($duration)->startOfMinute();

            if ($departure->isPast()) {
                $day->delete();

                continue;
            }

            $day->forceFill([
                'departure_at' => $departure->copy()->utc(),
                'departure_local' => $departure->format('Y-m-d H:i:s'),
                'promised_arrival_at' => $arrival->copy()->utc(),
            ])->save();
        }

        $offer->weekdaySchedules()->update(['departure_time' => now($timezone)->setTime(9, 0)->subSeconds($duration)->startOfMinute()->format('H:i:s')]);
    }

    private function comingDays(CommuteOffer $offer): mixed
    {
        return ScheduledTrip::query()
            ->where('commute_offer_id', $offer->id)
            ->where('status', ScheduledTripStatus::Scheduled->value)
            ->where('departure_at', '>', now())
            ->orderBy('departure_at')
            ->with('commuteOffer')
            ->get();
    }

    private function passengers(CommuteOffer $offer, mixed $days): void
    {
        $inLock = $this->moveNext($offer, self::SHORT_NOTICE_MINUTES);
        $days = $this->comingDays($offer)->reject(fn (ScheduledTrip $day): bool => $day->id === $inLock->id)->values();

        foreach (['Salma', 'Nour', 'Hana'] as $i => $name) {
            $this->seat($offer, $inLock, $this->rider($name), 0.2 + $i * 0.1, 0.8);
        }

        $this->seat($offer, $days[0], $this->rider('Salma'), 0.2, 0.8);
        $this->seat($offer, $days[0], $this->rider('Nour'), 0.3, 0.9);
        $this->seat($offer, $days[1], $this->rider('Mariam'), 0.25, 0.85);
    }

    private function mixedList(User $driver, CommuteOffer $published, mixed $offers): void
    {
        $paused = $offers->get(1) ?? $this->extraCommute($driver, '12:00:00');
        $archived = $offers->get(2) ?? $this->extraCommute($driver, '18:00:00');

        app(ChangeCommuteStatusAction::class)->pause($paused->refresh(), CommutePausedReason::ByDriver);
        app(ChangeCommuteStatusAction::class)->archive($archived->refresh());

        $this->seat($published, $this->comingDays($published)->first(), $this->rider('Salma'), 0.2, 0.8);
    }

    private function extraCommute(User $driver, string $arrival): CommuteOffer
    {
        $first = CommuteOffer::query()->where('driver_profile_id', $driver->id)->with('locations')->orderBy('id')->firstOrFail();
        $origin = $first->locations->firstWhere('type', CommuteLocationType::Origin);
        $destination = $first->locations->firstWhere('type', CommuteLocationType::Destination);
        $profile = DriverProfile::query()->whereKey($driver->id)->firstOrFail();

        $draft = app(CreateCommuteOfferAction::class)->execute($profile, ['vehicleId' => $first->vehicle_id]);

        app(SaveCommuteRouteAction::class)->execute($draft, [
            'origin' => ['lat' => (float) $origin->lat, 'lng' => (float) $origin->lng, 'address' => $origin->address],
            'destination' => ['lat' => (float) $destination->lat, 'lng' => (float) $destination->lng, 'address' => $destination->address],
        ]);

        app(SaveCommuteScheduleAction::class)->execute($draft->refresh(), ['daysMask' => 62, 'promisedArrivalTime' => $arrival]);

        return app(PublishCommuteOfferAction::class)->execute($draft->refresh())->load('locations', 'schedule');
    }

    private function paused(CommuteOffer $offer, mixed $days, CommutePausedReason $reason): void
    {
        $this->seatToLose($offer, $days);

        app(ChangeCommuteStatusAction::class)->pause($offer->refresh(), $reason);
    }

    private function seatToLose(CommuteOffer $offer, mixed $days): void
    {
        $this->seat($offer, $days[0], $this->rider('Salma'), 0.2, 0.8);
        $this->seat($offer, $days[1], $this->rider('Nour'), 0.3, 0.9);
    }

    private function pausedVehicle(CommuteOffer $offer, mixed $days): void
    {
        $this->paused($offer, $days, CommutePausedReason::VehicleSuspended);

        Vehicle::query()->whereKey($offer->vehicle_id)->update(['verification_status' => VehicleVerificationStatus::Suspended->value]);
    }

    private function pausedLicence(User $driver, CommuteOffer $offer, mixed $days): void
    {
        $this->paused($offer, $days, CommutePausedReason::LicenceExpired);

        DriverProfile::query()->whereKey($driver->id)->update([
            'status' => DriverProfileStatus::ExpiredDocuments->value,
            'licence_expiry' => now()->subDay()->startOfDay(),
        ]);
    }

    private function pausedCommission(User $driver, CommuteOffer $offer, mixed $days): void
    {
        $this->paused($offer, $days, CommutePausedReason::CommissionUnpaid);

        DebtLedger::record(
            $driver->id,
            DebtSide::Driver,
            DebtEntryType::Commission,
            18_000,
            'seat_commission',
            weekOf: SettlementWeek::shift(SettlementWeek::current(), -1),
        );

        DebtLedger::lockedAccount($driver->id, DebtSide::Driver)->forceFill(['suspended_at' => now()])->save();
    }

    private function pausedAccount(User $driver, CommuteOffer $offer, mixed $days): void
    {
        $this->seatToLose($offer, $days);

        app(SuspendMemberAction::class)->suspend($driver->refresh(), AdminUser::query()->firstOrFail(), SuspensionReason::SafetyReport, 'Demo account for a whole-account suspension.');
    }

    private function pausedDriver(User $driver, CommuteOffer $offer, mixed $days): void
    {
        $this->seatToLose($offer, $days);

        app(ReviewDriverApplicationAction::class)->suspend(DriverProfile::query()->whereKey($driver->id)->firstOrFail(), AdminUser::query()->firstOrFail(), 'Repeated no-show reports.');
    }

    private function pausedGender(User $driver, CommuteOffer $offer, mixed $days): void
    {
        $offer->forceFill(['audience' => CommuteAudience::WomenOnly->value])->save();

        $this->paused($offer, $days, CommutePausedReason::GenderReview);

        $driver->forceFill(['gender' => Gender::Man->value])->save();
    }

    private function pastDays(CommuteOffer $offer, mixed $days): void
    {
        $template = $days[0];

        $completed = $this->pastDay($offer, $template, 2, ScheduledTripStatus::Completed);
        $booking = $this->seat($offer, $completed, $this->rider('Salma'), 0.2, 0.8, BookingStatus::Completed);
        $second = $this->seat($offer, $completed, $this->rider('Nour'), 0.3, 0.9, BookingStatus::Completed);
        $this->finishedSession($completed);
        $this->rate($booking, 5);
        $this->rate($second, 4);

        $byDriver = $this->pastDay($offer, $template, 3, ScheduledTripStatus::Cancelled);
        $byDriver->forceFill(['cancelled_reason' => 'driver_cancelled_day', 'cancelled_by' => TripCancelledBy::Driver->value, 'seats_taken' => 0])->save();
        $this->seat($offer, $byDriver, $this->rider('Hana'), 0.2, 0.8, BookingStatus::CancelledByDriver);

        $byPlatform = $this->pastDay($offer, $template, 4, ScheduledTripStatus::Cancelled);
        $byPlatform->forceFill(['cancelled_reason' => 'schedule_ended', 'cancelled_by' => TripCancelledBy::Platform->value, 'seats_taken' => 0])->save();

        $expired = $this->pastDay($offer, $template, 5, ScheduledTripStatus::Expired);
        $expired->forceFill(['expired_at' => $expired->departure_at->copy()->addMinutes(20), 'seats_taken' => 0])->save();
    }

    private function pastDay(CommuteOffer $offer, ScheduledTrip $template, int $daysAgo, ScheduledTripStatus $status): ScheduledTrip
    {
        $timezone = $offer->schedule->timezone;
        $day = now($timezone)->subDays($daysAgo)->setTime(7, 0);

        $past = $template->replicate();
        $past->forceFill([
            'trip_date' => $day->toDateString(),
            'departure_at' => $day->copy()->utc(),
            'departure_local' => $day->format('Y-m-d H:i:s'),
            'promised_arrival_at' => $day->copy()->setTime(9, 0)->utc(),
            'status' => $status->value,
            'seats_taken' => 0,
        ])->save();

        return $past->load('commuteOffer');
    }

    private function finishedSession(ScheduledTrip $day): void
    {
        $session = new TripSession;
        $session->fill(['scheduled_trip_id' => $day->id, 'started_at' => $day->departure_at->copy()->subMinutes(3)]);
        $session->departed_at = $day->departure_at;
        $session->completed_at = $day->departure_at->copy()->addMinutes(64);
        $session->current_status = TripSessionStatus::Completed->value;
        $session->save();
    }

    private function rate(Booking $booking, int $stars): void
    {
        $rating = new Rating;
        $rating->forceFill([
            'booking_id' => $booking->id,
            'reviewer_user_id' => $booking->passenger_user_id,
            'reviewed_user_id' => $booking->driver_profile_id,
            'direction' => RatingDirection::PassengerToDriver->value,
            'kind' => RatingKind::CompletedRide->value,
            'stars' => $stars,
            'visible_at' => now()->subDay(),
        ])->save();
    }

    private function fees(CommuteOffer $offer, int $minutes): void
    {
        $day = $this->moveNext($offer, $minutes);

        $this->seat($offer, $day, $this->rider('Salma'), 0.2, 0.8);
        $this->seat($offer, $day, $this->rider('Nour'), 0.3, 0.9);
    }

    private function tripStarted(CommuteOffer $offer): void
    {
        $day = $this->moveNext($offer, 5);

        $this->seat($offer, $day, $this->rider('Salma'), 0.2, 0.8);

        $session = new TripSession;
        $session->fill(['scheduled_trip_id' => $day->id, 'started_at' => now()]);
        $session->current_status = TripSessionStatus::Preparing->value;
        $session->save();

        $day->forceFill(['status' => ScheduledTripStatus::Preparing->value])->save();
    }

    private function proposalOpen(CommuteOffer $offer): void
    {
        [$proposal, $bookings] = $this->proposal($offer, 3);

        app(RespondToChangeProposalAction::class)->respond($proposal, $bookings[0], ChangeApprovalResponse::Accepted);
    }

    private function proposalRevised(CommuteOffer $offer): void
    {
        [$proposal, $bookings] = $this->proposal($offer, 2);

        app(RespondToChangeProposalAction::class)->respond($proposal, $bookings[0], ChangeApprovalResponse::Accepted);
        app(ProposeCommuteChangeAction::class)->revise($proposal->refresh(), ['promisedArrivalTime' => '08:15:00']);
    }

    private function proposalRejected(CommuteOffer $offer): void
    {
        [$proposal, $bookings] = $this->proposal($offer, 2);

        app(RespondToChangeProposalAction::class)->respond($proposal, $bookings[0], ChangeApprovalResponse::Accepted);
        app(RespondToChangeProposalAction::class)->respond($proposal->refresh(), $bookings[1], ChangeApprovalResponse::Rejected);
    }

    private function proposalApplied(CommuteOffer $offer): void
    {
        [$proposal, $bookings] = $this->proposal($offer, 2);

        app(RespondToChangeProposalAction::class)->respond($proposal, $bookings[0], ChangeApprovalResponse::Accepted);
        app(RespondToChangeProposalAction::class)->respond($proposal->refresh(), $bookings[1], ChangeApprovalResponse::Accepted);
    }

    private function proposalExpired(CommuteOffer $offer): void
    {
        [$proposal] = $this->proposal($offer, 2);

        $proposal->forceFill(['status' => ChangeProposalStatus::Expired->value, 'expires_at' => now()->subHour()])->save();
    }

    private function proposal(CommuteOffer $offer, int $riders): array
    {
        $day = $this->comingDays($offer)->first();
        $names = array_slice(array_keys(self::RIDERS), 0, $riders);
        $bookings = [];

        foreach ($names as $i => $name) {
            $bookings[] = $this->seat($offer, $day, $this->rider($name), 0.2 + $i * 0.1, 0.8);
        }

        $proposal = app(ProposeCommuteChangeAction::class)->propose($offer->refresh(), [
            'scheduledTripId' => $day->id,
            'scope' => ChangeProposalScope::OnlyThisOccurrence->value,
            'promisedArrivalTime' => '08:30:00',
        ]);

        return [$proposal->load('scheduledTrip'), $bookings];
    }

    private function itinerary(CommuteOffer $offer): void
    {
        $day = $this->comingDays($offer)->first();

        $this->seat($offer, $day, $this->rider('Salma'), 0.2, 0.7);
        $this->seat($offer, $day, $this->rider('Nour'), 0.4, 0.9);
    }

    private function overLimit(CommuteOffer $offer): void
    {
        $day = $this->comingDays($offer)->first();

        $day->forceFill(['seats_total' => 6])->save();

        foreach (array_slice(array_keys(self::RIDERS), 0, 6) as $i => $name) {
            $this->seat($offer, $day, $this->rider($name), 0.1 + $i * 0.05, 0.5 + $i * 0.07);
        }
    }

    private function waitlist(CommuteOffer $offer, mixed $days): void
    {
        $this->seat($offer, $days[0], $this->rider('Dina'), 0.2, 0.8);

        $this->wait($offer, $this->rider('Layla'), 2, null);
        $this->wait($offer, $this->rider('Rana'), 3, $days[1]);
        $this->wait($offer, $this->rider('Reem'), 4, null);

        $this->seatOnAnotherCommute($this->rider('Reem'));

        app(ChangeCommuteStatusAction::class)->pause($offer->refresh(), CommutePausedReason::ByDriver);
    }

    private function offers(CommuteOffer $offer, mixed $days): void
    {
        $open = $this->wait($offer, $this->rider('Salma'), 1, $days[0]);
        $withdrawn = $this->wait($offer, $this->rider('Nour'), 2, $days[1]);
        $accepted = $this->wait($offer, $this->rider('Hana'), 3, $days[2]);
        $taken = $this->wait($offer, $this->rider('Mariam'), 4, $days[2]);

        $this->offerRound($days[0], [$open->id => SeatOfferStatus::Open]);
        $this->offerRound($days[1], [$withdrawn->id => SeatOfferStatus::Withdrawn]);
        $this->offerRound($days[2], [$accepted->id => SeatOfferStatus::Accepted, $taken->id => SeatOfferStatus::TakenByOther]);

        $accepted->forceFill(['status' => SeatRequestStatus::Approved->value, 'waitlist_position' => null])->save();
        $this->seat($offer, $days[2], $this->rider('Hana'), 0.3, 0.8);
    }

    private function replace(CommuteOffer $offer, int $minutes): array
    {
        $day = $this->moveNext($offer, $minutes);

        $day->forceFill(['seats_total' => 2])->save();

        $booking = $this->seat($offer, $day, $this->rider('Salma'), 0.2, 0.8);
        $this->seat($offer, $day, $this->rider('Nour'), 0.3, 0.9);

        return [$booking, $this->wait($offer, $this->rider('Rana'), 1, $day), $this->wait($offer, $this->rider('Aya'), 2, $day)];
    }

    private function replacePending(CommuteOffer $offer): void
    {
        [$booking, $rana] = $this->replace($offer, self::OUTSIDE_LOCK_MINUTES);

        app(OfferReplacementAction::class)->execute($booking, $rana->id);
    }

    private function replaceLocked(CommuteOffer $offer): void
    {
        [$booking, , $aya] = $this->replace($offer, self::SHORT_NOTICE_MINUTES);

        $aya->forceFill([
            'requested_pickup_lat' => $booking->pickup_lat,
            'requested_pickup_lng' => $booking->pickup_lng,
            'requested_pickup_point' => new Coordinate((float) $booking->pickup_lat, (float) $booking->pickup_lng),
            'requested_dropoff_lat' => $booking->dropoff_lat,
            'requested_dropoff_lng' => $booking->dropoff_lng,
            'requested_dropoff_point' => new Coordinate((float) $booking->dropoff_lat, (float) $booking->dropoff_lng),
        ])->save();
    }

    private function moveNext(CommuteOffer $offer, int $minutes): ScheduledTrip
    {
        $day = $this->comingDays($offer)->first();
        $timezone = $offer->schedule->timezone;
        $departure = now()->addMinutes($minutes)->startOfMinute();

        $moved = ScheduledTrip::query()
            ->where('commute_offer_id', $offer->id)
            ->where('trip_date', $departure->copy()->setTimezone($timezone)->toDateString())
            ->whereKeyNot($day->id)
            ->first();

        if ($moved !== null) {
            $day = $moved;
        }

        $day->forceFill([
            'trip_date' => $departure->copy()->setTimezone($timezone)->toDateString(),
            'departure_at' => $departure,
            'departure_local' => $departure->copy()->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'promised_arrival_at' => $departure->copy()->addMinutes(120),
            'status' => ScheduledTripStatus::Scheduled->value,
        ])->save();

        return $day->refresh()->load('commuteOffer');
    }

    private function seat(CommuteOffer $offer, ScheduledTrip $day, User $rider, float $pickupShare, float $dropoffShare, BookingStatus $status = BookingStatus::Confirmed): Booking
    {
        $pickup = $this->along($offer, $pickupShare);
        $dropoff = $this->along($offer, $dropoffShare);

        $request = SeatRequest::query()
            ->where('commute_offer_id', $offer->id)
            ->where('passenger_user_id', $rider->id)
            ->where('status', SeatRequestStatus::Approved->value)
            ->first() ?? $this->ask($offer, $rider, [
                'status' => SeatRequestStatus::Approved,
                'scheduled_trip_id' => $day->id,
                'commitment' => SeatRequestCommitment::Trial,
                'requested_pickup_point' => $pickup,
                'requested_pickup_lat' => $pickup->lat,
                'requested_pickup_lng' => $pickup->lng,
                'requested_dropoff_point' => $dropoff,
                'requested_dropoff_lat' => $dropoff->lat,
                'requested_dropoff_lng' => $dropoff->lng,
                'responded_at' => now()->subDay(),
            ]);

        $this->seatOn($rider, $day->load('commuteOffer'));

        $booking = Booking::query()->where('scheduled_trip_id', $day->id)->where('passenger_user_id', $rider->id)->latest('id')->firstOrFail();

        $booking->forceFill([
            'status' => $status->value,
            'seat_request_id' => $request->id,
            'pickup_point' => $pickup,
            'pickup_lat' => $pickup->lat,
            'pickup_lng' => $pickup->lng,
            'pickup_label' => $request->requested_pickup_label,
            'dropoff_point' => $dropoff,
            'dropoff_lat' => $dropoff->lat,
            'dropoff_lng' => $dropoff->lng,
            'dropoff_label' => $request->requested_dropoff_label,
            'cancelled_at' => $status === BookingStatus::CancelledByDriver ? $day->departure_at->copy()->subHours(5) : null,
            'cancelled_reason' => $status === BookingStatus::CancelledByDriver ? 'driver_cancelled_day' : null,
        ])->save();

        return $booking;
    }

    private function ask(CommuteOffer $offer, User $rider, array $overrides = []): SeatRequest
    {
        $pickup = $this->along($offer, 0.3);
        $dropoff = $this->along($offer, 0.8);

        return SeatRequest::factory()->create([
            'passenger_user_id' => $rider->id,
            'commute_offer_id' => $offer->id,
            'status' => SeatRequestStatus::Pending,
            'kind' => SeatRequestKind::SeatRequest,
            'requested_pickup_point' => $pickup,
            'requested_pickup_lat' => $pickup->lat,
            'requested_pickup_lng' => $pickup->lng,
            'requested_pickup_label' => 'Al Rehab Mall entrance',
            'requested_pickup_source' => PointSource::CustomMap,
            'requested_dropoff_point' => $dropoff,
            'requested_dropoff_lat' => $dropoff->lat,
            'requested_dropoff_lng' => $dropoff->lng,
            'requested_dropoff_label' => 'Smart Village B12',
            'requested_dropoff_source' => PointSource::CustomMap,
            ...$overrides,
        ]);
    }

    private function wait(CommuteOffer $offer, User $rider, int $position, ?ScheduledTrip $day): SeatRequest
    {
        return $this->ask($offer, $rider, [
            'status' => SeatRequestStatus::Waitlisted,
            'waitlist_position' => $position,
            'responded_at' => now()->subDays($position),
            'scheduled_trip_id' => $day?->id,
            'commitment' => $day === null ? SeatRequestCommitment::Recurring : SeatRequestCommitment::Trial,
            'requested_days_mask' => $day === null ? 127 : null,
        ]);
    }

    private function along(CommuteOffer $offer, float $share): Coordinate
    {
        $offer->loadMissing('locations');

        $origin = $offer->locations->firstWhere('type', CommuteLocationType::Origin);
        $destination = $offer->locations->firstWhere('type', CommuteLocationType::Destination);

        return new Coordinate(
            (float) $origin->lat + ((float) $destination->lat - (float) $origin->lat) * $share,
            (float) $origin->lng + ((float) $destination->lng - (float) $origin->lng) * $share,
        );
    }

    private function rider(string $name): User
    {
        return $this->person(self::RIDERS[$name], $name, [Capability::Passenger]);
    }

    private function seatOnAnotherCommute(User $rider): void
    {
        $driver = $this->commuteDriver('DC-OTHER', self::OTHER_COMMUTE_DRIVER);
        $offer = CommuteOffer::query()->where('driver_profile_id', $driver->id)->orderBy('id')->firstOrFail();
        $day = $this->comingDays($offer)->first();

        if (! Booking::query()->where('scheduled_trip_id', $day->id)->where('passenger_user_id', $rider->id)->where('status', BookingStatus::Confirmed->value)->exists()) {
            $this->seatOnce($rider, $day);
        }
    }

    private function offerRound(ScheduledTrip $day, array $statuses): void
    {
        $release = (string) Str::ulid();

        foreach ($statuses as $seatRequestId => $status) {
            $offer = new SeatOffer;
            $offer->forceFill([
                'scheduled_trip_id' => $day->id,
                'seat_request_id' => $seatRequestId,
                'status' => $status->value,
                'release_event_id' => $release,
                'closed_at' => $status === SeatOfferStatus::Open ? null : now(),
            ])->save();
        }
    }

    private function clearDebt(string $driverId): void
    {
        DebtEntry::query()->where('user_id', $driverId)->delete();
        DebtAccount::query()->where('user_id', $driverId)->delete();
    }
}
