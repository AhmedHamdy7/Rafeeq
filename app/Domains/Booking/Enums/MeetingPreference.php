<?php

namespace App\Domains\Booking\Enums;

enum MeetingPreference: string
{
    case Gate = 'gate';
    case Street = 'street';
    case Landmark = 'landmark';
    case Custom = 'custom';

    /**
     * @param  array<string, mixed>  $pickup
     */
    public static function forPickup(array $pickup): self
    {
        return empty($pickup['suggestionId']) ? self::Custom : self::Landmark;
    }
}
