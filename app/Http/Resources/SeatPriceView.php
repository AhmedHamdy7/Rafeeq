<?php

namespace App\Http\Resources;

use App\Domains\Commute\Models\CommuteOffer;
use Illuminate\Http\Request;

/**
 * Who is looking at a price, so a payload knows which figures it may carry (P-3, PAY-39).
 *
 * 🔒 The rule: a passenger sees the seat price and nothing else; the commute's own driver sees the
 * seat price and his share ("Seat EGP 110 · You keep EGP 100"); NOBODY is ever sent the commission
 * rate. Every booking and trip-day shape is read by both sides, so the decision cannot be made by
 * the route — it has to be made by the viewer, and made in one place so the shapes cannot drift.
 *
 * 🔴 Answered with at most one small query cached on the request, not through relations, because
 * these shapes are rendered in lists under `preventLazyLoading`: the ids of the commutes the viewer
 * drives. A driver has a handful, so every row in a list is answered from memory.
 */
final class SeatPriceView
{
    private const string COMMUTES_KEY = 'rafeeq.priceView.commuteIds';

    /** Is the signed-in viewer the driver with this driver-profile id? */
    public static function viewerDrives(Request $request, ?string $driverProfileId): bool
    {
        if ($driverProfileId === null) {
            return false;
        }

        return self::viewerProfileId($request) === $driverProfileId;
    }

    /** Is the signed-in viewer the driver of this commute? */
    public static function viewerDrivesCommute(Request $request, ?string $commuteOfferId): bool
    {
        if ($commuteOfferId === null || self::viewerProfileId($request) === null) {
            return false;
        }

        if (! $request->attributes->has(self::COMMUTES_KEY)) {
            $ids = CommuteOffer::query()
                ->where('driver_profile_id', self::viewerProfileId($request))
                ->pluck('id')
                ->all();

            $request->attributes->set(self::COMMUTES_KEY, array_flip($ids));
        }

        return isset($request->attributes->get(self::COMMUTES_KEY)[$commuteOfferId]);
    }

    /**
     * `driver_profiles` is keyed by `user_id`, so a driver-profile id IS a user id: comparing the
     * viewer's own id answers "is this his" without a query.
     */
    private static function viewerProfileId(Request $request): ?string
    {
        $id = $request->user()?->getKey();

        return $id === null ? null : (string) $id;
    }
}
