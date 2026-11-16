<?php

namespace App\Domains\Booking\Enums;

enum SeatOfferClosedReason: string
{
    case CarPassed = 'car_passed';
    case AlreadyBooked = 'already_booked';
    case SeatTaken = 'seat_taken';
    case Declined = 'declined';
    case WithdrawnByDriver = 'withdrawn_by_driver';
    case RequestWithdrawn = 'request_withdrawn';
    case RequestChanged = 'request_changed';
    case DayCancelled = 'day_cancelled';
    case CommutePaused = 'commute_paused';
    case CommuteDeleted = 'commute_deleted';
    case VehicleReplaced = 'vehicle_replaced';
    case ReplacedBookingCancelled = 'replaced_booking_cancelled';

    public static function forDisplacement(DisplacementReason $reason): self
    {
        return match ($reason) {
            DisplacementReason::CommutePaused => self::CommutePaused,
            DisplacementReason::VehicleReplaced => self::VehicleReplaced,
            DisplacementReason::CommuteDeleted => self::CommuteDeleted,
        };
    }

    public static function legacy(SeatOfferStatus $status): ?self
    {
        return match ($status) {
            SeatOfferStatus::TakenByOther => self::SeatTaken,
            SeatOfferStatus::Ineligible => self::CarPassed,
            SeatOfferStatus::Declined => self::Declined,
            SeatOfferStatus::Withdrawn => self::WithdrawnByDriver,
            default => null,
        };
    }
}
