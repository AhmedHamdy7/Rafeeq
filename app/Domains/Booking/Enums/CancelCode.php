<?php

namespace App\Domains\Booking\Enums;

use App\Domains\Booking\Models\Booking;
use App\Domains\Trip\Enums\ActiveTripCancelReason;

enum CancelCode: string
{
    case RemovedByDriver = 'removed_by_driver';
    case DayCancelled = 'day_cancelled';
    case CommutePaused = 'commute_paused';
    case CommuteDeleted = 'commute_deleted';
    case CommuteEnded = 'commute_ended';
    case CommuteStopped = 'commute_stopped';
    case VehicleReplaced = 'vehicle_replaced';
    case Replaced = 'replaced';
    case ActiveTripCancelled = 'active_trip_cancelled';
    case TripExpired = 'trip_expired';
    case NotStarted = 'not_started';
    case PassedYourStop = 'passed_your_stop';
    case LateForPickup = 'late_for_pickup';
    case PassengerCancelled = 'passenger_cancelled';
    case LeftGroup = 'left_group';
    case AbsencePlanned = 'absence_planned';
    case DeclaredAway = 'declared_away';
    case EndedEarly = 'ended_early';
    case Switched = 'switched';
    case Blocked = 'blocked';
    case AccountSuspended = 'account_suspended';
    case AccountDeleted = 'account_deleted';

    public static function of(Booking $booking): ?self
    {
        if ($booking->cancelled_at === null) {
            return null;
        }

        if ($booking->cancel_code !== null && ($code = self::tryFrom($booking->cancel_code)) !== null) {
            return $code;
        }

        return self::fromLegacy($booking->status, $booking->cancelled_reason);
    }

    public static function fromLegacy(BookingStatus $status, ?string $reason): ?self
    {
        $known = match ($reason) {
            'driver_replaced' => self::Replaced,
            DisplacementReason::CommutePaused->value => self::CommutePaused,
            DisplacementReason::VehicleReplaced->value => self::VehicleReplaced,
            DisplacementReason::CommuteDeleted->value => self::CommuteDeleted,
            'driver_cancelled_day' => self::DayCancelled,
            SwitchReason::Voluntary->value, SwitchReason::DriverDelay->value => self::Switched,
            'trip_expired' => self::TripExpired,
            'not_started' => self::NotStarted,
            'passed_your_stop' => self::PassedYourStop,
            'late_for_pickup' => self::LateForPickup,
            'declared_away' => self::DeclaredAway,
            'blocked' => self::Blocked,
            'account_suspended', 'debt_suspended' => self::AccountSuspended,
            'account_deleted' => self::AccountDeleted,
            default => null,
        };

        if ($known !== null) {
            return $known;
        }

        if (self::activeTripCategory($reason) !== null
            && in_array($status, [BookingStatus::EndedEarly, BookingStatus::CancelledByDriver], true)) {
            return self::ActiveTripCancelled;
        }

        return match ($status) {
            BookingStatus::CancelledByDriver => self::RemovedByDriver,
            BookingStatus::CancelledByPassenger => self::PassengerCancelled,
            BookingStatus::EndedEarly => self::EndedEarly,
            BookingStatus::ExitedDueToDriverDelay => self::LateForPickup,
            default => null,
        };
    }

    public static function activeTripCategory(?string $reason): ?ActiveTripCancelReason
    {
        if ($reason === null) {
            return null;
        }

        return ActiveTripCancelReason::tryFrom(trim(explode(':', $reason, 2)[0]));
    }

    public static function forDisplacement(DisplacementReason $reason): self
    {
        return match ($reason) {
            DisplacementReason::CommutePaused => self::CommutePaused,
            DisplacementReason::VehicleReplaced => self::VehicleReplaced,
            DisplacementReason::CommuteDeleted => self::CommuteDeleted,
        };
    }

    public function isDriverSide(): bool
    {
        return in_array($this, [
            self::RemovedByDriver, self::DayCancelled, self::CommutePaused, self::CommuteDeleted,
            self::CommuteEnded, self::CommuteStopped, self::VehicleReplaced, self::Replaced,
            self::ActiveTripCancelled, self::TripExpired, self::NotStarted, self::PassedYourStop, self::LateForPickup,
        ], true);
    }

    public function writtenByDriver(): bool
    {
        return in_array($this, [self::RemovedByDriver, self::ActiveTripCancelled], true);
    }
}
