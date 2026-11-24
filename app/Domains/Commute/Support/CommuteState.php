<?php

namespace App\Domains\Commute\Support;

use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteOffer;

final class CommuteState
{
    public static function status(?CommuteOffer $offer): ?string
    {
        return match ($offer?->status) {
            null => null,
            CommuteOfferStatus::Published => 'active',
            default => $offer->status->value,
        };
    }

    /**
     * @return array{commuteStatus: ?string, commutePausedReason: ?string}
     */
    public static function fields(?CommuteOffer $offer): array
    {
        return [
            'commuteStatus' => self::status($offer),
            'commutePausedReason' => $offer?->status === CommuteOfferStatus::Paused ? $offer->paused_reason?->value : null,
        ];
    }
}
