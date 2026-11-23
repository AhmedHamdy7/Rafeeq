<?php

namespace App\Http\Resources;

use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteWeekdaySchedule;
use App\Domains\Shared\ValueObjects\DaysMask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The link between a Going commute and its Return (B3 of 2026-11-23).
 *
 * The driver sees the other side in any live state, drafts included. A passenger sees it only while
 * it is published (a draft or paused Return is `null` for her, S10).
 */
final class CommuteLink
{
    /**
     * @return array{returnOfCommuteId: string|null, goingCommute: array<string, mixed>|null, returnCommute: array<string, mixed>|null}
     */
    public static function forDriver(CommuteOffer $offer): array
    {
        return self::build($offer, passenger: false);
    }

    /**
     * @return array{returnOfCommuteId: string|null, goingCommute: array<string, mixed>|null, returnCommute: array<string, mixed>|null}
     */
    public static function forPassenger(CommuteOffer $offer): array
    {
        return self::build($offer, passenger: true);
    }

    /**
     * @return array{id: string, status: string, departureLocal: string|null, daysMask: int}|null
     */
    public static function returnOf(CommuteOffer $offer, bool $passenger): ?array
    {
        if ($offer->isReturn()) {
            return null;
        }

        $return = $offer->relationLoaded('returnCommute')
            ? $offer->returnCommute
            : $offer->returnCommute()->with(['weekdaySchedules', 'schedule'])->first();

        if ($return === null || ($passenger && $return->status !== CommuteOfferStatus::Published)) {
            return null;
        }

        return [
            'id' => (string) $return->getKey(),
            'status' => strtoupper($return->status->value),
            'departureLocal' => self::sharedOrNext(self::days($return), 'promised_departure_time'),
            'daysMask' => (int) self::mask($return),
        ];
    }

    /**
     * @return array{id: string, status: string, promisedArrivalLocal: string|null, daysMask: int}|null
     */
    public static function goingOf(CommuteOffer $offer, bool $passenger): ?array
    {
        if (! $offer->isReturn() || $offer->return_of_commute_id === null) {
            return null;
        }

        $going = $offer->relationLoaded('goingCommute')
            ? $offer->goingCommute
            : $offer->goingCommute()->with(['weekdaySchedules', 'schedule'])->first();

        if ($going === null
            || $going->status === CommuteOfferStatus::Archived
            || ($passenger && $going->status !== CommuteOfferStatus::Published)) {
            return null;
        }

        return [
            'id' => (string) $going->getKey(),
            'status' => strtoupper($going->status->value),
            'promisedArrivalLocal' => self::sharedOrNext(self::days($going), 'promised_arrival_time'),
            'daysMask' => (int) self::mask($going),
        ];
    }

    /**
     * @return array{returnOfCommuteId: string|null, goingCommute: array<string, mixed>|null, returnCommute: array<string, mixed>|null}
     */
    private static function build(CommuteOffer $offer, bool $passenger): array
    {
        $going = self::goingOf($offer, $passenger);

        return [
            'returnOfCommuteId' => $passenger && $going === null ? null : $offer->return_of_commute_id,
            'goingCommute' => $going,
            'returnCommute' => self::returnOf($offer, $passenger),
        ];
    }

    /**
     * @return Collection<int, CommuteWeekdaySchedule>
     */
    public static function days(CommuteOffer $offer): Collection
    {
        return $offer->relationLoaded('weekdaySchedules') ? $offer->weekdaySchedules : $offer->weekdaySchedules()->get();
    }

    private static function mask(CommuteOffer $offer): int
    {
        $schedule = $offer->relationLoaded('schedule') ? $offer->schedule : $offer->schedule()->first();

        return (int) ($schedule?->days_mask ?? 0);
    }

    /**
     * The one time every day shares, or the time of the next day it runs from today; `HH:MM`.
     *
     * @param  Collection<int, CommuteWeekdaySchedule>  $days
     */
    public static function sharedOrNext(Collection $days, string $column): ?string
    {
        $times = $days->pluck($column)->filter()->map(fn ($time): string => substr((string) $time, 0, 5))->unique()->values();

        if ($times->isEmpty()) {
            return null;
        }

        if ($times->count() === 1) {
            return $times->first();
        }

        $date = CarbonImmutable::today();

        for ($i = 0; $i < 7; $i++, $date = $date->addDay()) {
            $day = $days->firstWhere('weekday_bit', DaysMask::bitForDate($date));

            if ($day !== null && $day->{$column} !== null) {
                return substr((string) $day->{$column}, 0, 5);
            }
        }

        return $times->first();
    }
}
