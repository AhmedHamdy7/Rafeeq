<?php

namespace App\Domains\Booking\Support;

use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Identity\Enums\Gender;
use App\Domains\Identity\Models\User;
use App\Domains\Matching\Support\HardFilters;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Support\DebtStanding;

final class RequestEligibility
{
    public const array OPEN_REQUEST_STATUSES = [
        SeatRequestStatus::Pending,
        SeatRequestStatus::Approved,
        SeatRequestStatus::Waitlisted,
        SeatRequestStatus::Counteroffered,
    ];

    /**
     * @return array{canRequest: bool, reason: string|null}
     */
    public static function for(User $passenger, CommuteOffer $offer): array
    {
        $reason = match (true) {
            $offer->driver_profile_id === $passenger->id => 'own_commute',
            DebtStanding::of($passenger, DebtSide::Passenger)->suspendedAt !== null => 'account_restricted',
            ! HardFilters::mayJoinAudience($passenger, $offer) => $passenger->gender === Gender::PreferNotToSay
                ? 'gender_not_set'
                : 'women_only',
            self::hasOpenRequest($passenger, $offer) => 'already_requested',
            default => null,
        };

        return ['canRequest' => $reason === null, 'reason' => $reason];
    }

    public static function hasOpenRequest(User $passenger, CommuteOffer $offer): bool
    {
        return SeatRequest::query()
            ->where('passenger_user_id', $passenger->id)
            ->where('commute_offer_id', $offer->id)
            ->whereIn('status', array_map(fn (SeatRequestStatus $status): string => $status->value, self::OPEN_REQUEST_STATUSES))
            ->exists();
    }
}
