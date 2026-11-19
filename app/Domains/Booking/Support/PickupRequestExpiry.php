<?php

namespace App\Domains\Booking\Support;

use App\Domains\Booking\Enums\PickupPointRequestStatus;
use App\Domains\Booking\Models\PickupPointRequest;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PickupRequestExpiry
{
    public const array OPEN = [
        PickupPointRequestStatus::Pending,
        PickupPointRequestStatus::SuggestedAlternative,
    ];

    public static function deadlineFor(PickupPointRequest $pickup): ?CarbonImmutable
    {
        $departure = $pickup->seat_request_id !== null
            ? ScheduledTrip::query()->whereKey($pickup->seatRequest?->scheduled_trip_id)->value('departure_at')
            : PickupRequestContext::nextBooking($pickup)?->scheduledTrip?->departure_at;

        return $departure === null ? null : CarbonImmutable::parse($departure);
    }

    public static function stamp(PickupPointRequest $pickup): void
    {
        $pickup->forceFill(['expires_at' => self::deadlineFor($pickup)])->save();
    }

    public static function isOpen(PickupPointRequest $pickup): bool
    {
        return in_array($pickup->status, self::OPEN, true);
    }

    public static function expireOverdue(): int
    {
        PickupPointRequest::query()
            ->whereIn('status', array_map(fn (PickupPointRequestStatus $s): string => $s->value, self::OPEN))
            ->whereNull('expires_at')
            ->get()
            ->each(fn (PickupPointRequest $pickup) => self::stamp($pickup));

        $expired = 0;

        PickupPointRequest::query()
            ->whereIn('status', array_map(fn (PickupPointRequestStatus $s): string => $s->value, self::OPEN))
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get()
            ->each(function (PickupPointRequest $pickup) use (&$expired): void {
                $expired += self::expire($pickup) ? 1 : 0;
            });

        return $expired;
    }

    public static function expire(PickupPointRequest $pickup): bool
    {
        return DB::transaction(function () use ($pickup): bool {
            $fresh = PickupPointRequest::query()->whereKey($pickup->getKey())->lockForUpdate()->first();

            if ($fresh === null || ! self::isOpen($fresh)) {
                return false;
            }

            $fresh->forceFill([
                'status' => PickupPointRequestStatus::Expired->value,
                'expired_at' => now(),
                'expires_at' => $fresh->expires_at === null || $fresh->expires_at->isFuture() ? now() : $fresh->expires_at,
            ])->save();

            self::tell($fresh);

            $pickup->setRawAttributes($fresh->getAttributes(), true);

            return true;
        });
    }

    private static function tell(PickupPointRequest $pickup): void
    {
        $commuteId = PickupRequestContext::commuteId($pickup);
        $driverId = $commuteId === null ? null : CommuteOffer::query()->whereKey($commuteId)->value('driver_profile_id');
        $passenger = User::query()->find($pickup->requested_by_user_id);
        $driver = $driverId === null ? null : User::query()->find($driverId);

        $data = array_filter([
            'pickupRequestId' => $pickup->id,
            'commuteId' => $commuteId,
            'seatRequestId' => $pickup->seat_request_id,
        ]);

        if ($passenger !== null) {
            Notifier::send($passenger, NotificationType::PickupChangeExpired, params: [
                'name' => $driver?->public_first_name ?? '',
            ], data: $data);
        }

        if ($driver !== null) {
            Notifier::send($driver, NotificationType::PickupChangeExpiredForDriver, params: [
                'name' => $passenger?->public_first_name ?? '',
            ], data: $data);
        }
    }
}
