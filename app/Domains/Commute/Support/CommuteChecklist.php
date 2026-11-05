<?php

namespace App\Domains\Commute\Support;

use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\CommuteType;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Driver\Enums\VehicleVerificationStatus;

/**
 * What a commute still needs before it can be published — the review screen of
 * Chapter 4 and the check behind the Publish button, from one source.
 *
 * Returned as a list so the screen can show what is outstanding, and refused on
 * by the publish Action, so the two can never disagree about whether something
 * is ready.
 */
final class CommuteChecklist
{
    /**
     * @return array<int, string>
     */
    public static function missingFor(CommuteOffer $offer): array
    {
        $missing = [];

        $types = [];

        foreach ($offer->locations as $location) {
            $types[] = $location->type->value;
        }

        if (! in_array(CommuteLocationType::Origin->value, $types, true)) {
            $missing[] = 'origin';
        }

        if (! in_array(CommuteLocationType::Destination->value, $types, true)) {
            $missing[] = 'destination';
        }

        if ($offer->schedule === null) {
            $missing[] = 'schedule';
        }

        if ($offer->vehicle_id === null) {
            $missing[] = 'vehicle';
        }

        // A one-time commute still needs a schedule row: it is the same shape
        // with a single day in the mask and start_date == end_date, so nothing
        // downstream has to special-case it.
        if ($offer->commute_type === CommuteType::OneTime
            && $offer->schedule !== null
            && ! $offer->schedule->start_date->isSameDay($offer->schedule->end_date)) {
            $missing[] = 'one_time_single_date';
        }

        return $missing;
    }

    /**
     * @return array<int, string>
     */
    public static function forReview(CommuteOffer $offer): array
    {
        $missing = self::missingFor($offer);

        if ($offer->status !== CommuteOfferStatus::Draft || $offer->vehicle_id === null) {
            return $missing;
        }

        $vehicle = $offer->vehicle()->first();

        if ($vehicle?->verification_status === VehicleVerificationStatus::Rejected) {
            $missing[] = 'vehicle_rejected';
        } elseif ($vehicle !== null && (! $vehicle->isApproved() || $vehicle->approved_seats === null)) {
            $missing[] = 'vehicle_under_review';
        }

        return $missing;
    }
}
