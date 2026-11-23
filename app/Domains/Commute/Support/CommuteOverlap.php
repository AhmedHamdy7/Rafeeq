<?php

namespace App\Domains\Commute\Support;

use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteWeekdaySchedule;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\DaysMask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Two commutes of one driver clash when they share a weekday on a date both run, and one starts
 * before the other's arrival plus the gap (D3 of 2026-11-23, item 40).
 *
 * A Going day starts at its earliest possible departure (the computed one, less the whole-trip
 * detour it can still move earlier by) and ends at its promised arrival. A Return day starts at its
 * promised leave time and ends at its estimated arrival.
 */
final class CommuteOverlap
{
    /**
     * @param  Collection<int, CommuteWeekdaySchedule>|null  $days  the days to check instead of the saved ones
     */
    public static function assertNone(CommuteOffer $offer, ?Collection $days = null, ?string $startDate = null, ?string $endDate = null): void
    {
        $offer->loadMissing(['schedule', 'weekdaySchedules']);

        $startDate ??= $offer->schedule?->start_date?->toDateString();
        $endDate ??= $offer->schedule?->end_date?->toDateString();
        $days ??= $offer->weekdaySchedules;

        if ($startDate === null || $endDate === null || $days->isEmpty()) {
            return;
        }

        $gap = RouteSettings::commuteGapMinutes();

        $others = CommuteOffer::query()
            ->where('driver_profile_id', $offer->driver_profile_id)
            ->whereKeyNot($offer->getKey())
            ->whereIn('status', [CommuteOfferStatus::Published->value, CommuteOfferStatus::Paused->value])
            ->whereHas('schedule', fn ($query) => $query
                ->where('start_date', '<=', $endDate)
                ->where('end_date', '>=', $startDate))
            ->with(['schedule', 'weekdaySchedules', 'locations'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($others as $other) {
            $bits = [];
            $first = null;

            foreach ($days->sortBy('weekday_bit') as $day) {
                $clash = $other->weekdaySchedules->firstWhere('weekday_bit', (int) $day->weekday_bit);

                if ($clash === null || ! self::sharesWeekdayInDates((int) $day->weekday_bit, $startDate, $endDate, $other)) {
                    continue;
                }

                [$start, $end] = self::window($offer, $day);
                [$otherStart, $otherEnd] = self::window($other, $clash);

                if ($start < $otherEnd + $gap && $otherStart < $end + $gap) {
                    $bits[] = (int) $day->weekday_bit;
                    $first ??= [$start, $otherStart, $otherEnd];
                }
            }

            if ($first === null) {
                continue;
            }

            [$start, $otherStart, $otherEnd] = $first;
            $after = $start >= $otherStart;
            $route = self::labels($other);

            throw DomainException::of(ErrorCode::CommuteScheduleOverlap, fields: [
                'commuteId' => (string) $other->getKey(),
                'otherDirection' => $other->direction->value,
                'otherOriginLabel' => $route['originLabel'],
                'otherDestinationLabel' => $route['destinationLabel'],
                'weekdayBits' => $bits,
                'otherStartsAtLocal' => self::clock($otherStart),
                'otherArrivesAtLocal' => self::clock($otherEnd),
                'earliestStartLocal' => $after ? self::clock($otherEnd + $gap) : null,
                'latestArrivalLocal' => $after ? null : self::clock($otherStart - $gap),
                'gapMinutes' => $gap,
            ]);
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function window(CommuteOffer $offer, CommuteWeekdaySchedule $day): array
    {
        if ($offer->isReturn()) {
            $start = self::minutes((string) ($day->promised_departure_time ?? $day->departure_time));
            $end = $day->estimated_arrival_time !== null
                ? self::minutes((string) $day->estimated_arrival_time)
                : $start + intdiv((int) $offer->route_duration_seconds, 60);

            return [$start, max($start, $end)];
        }

        $departure = self::minutes((string) $day->departure_time);
        $end = $day->promised_arrival_time !== null ? self::minutes((string) $day->promised_arrival_time) : $departure;
        $start = max(0, $departure - RouteSettings::maxTripDetourMinutes());

        return [$start, max($departure, $end)];
    }

    private static function sharesWeekdayInDates(int $bit, string $startDate, string $endDate, CommuteOffer $other): bool
    {
        $from = max(CarbonImmutable::parse($startDate), CarbonImmutable::parse($other->schedule->start_date->toDateString()));
        $until = min(CarbonImmutable::parse($endDate), CarbonImmutable::parse($other->schedule->end_date->toDateString()));

        for ($date = $from, $i = 0; $date->lessThanOrEqualTo($until) && $i < 7; $date = $date->addDay(), $i++) {
            if (DaysMask::bitForDate($date) === $bit) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{originLabel: string|null, destinationLabel: string|null}
     */
    private static function labels(CommuteOffer $offer): array
    {
        $origin = $offer->locations->firstWhere('type', CommuteLocationType::Origin);
        $destination = $offer->locations->firstWhere('type', CommuteLocationType::Destination);

        return [
            'originLabel' => $origin?->address,
            'destinationLabel' => $destination?->address,
        ];
    }

    private static function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', array_slice(explode(':', $time), 0, 2));

        return $hours * 60 + $minutes;
    }

    private static function clock(int $minutes): string
    {
        $minutes = max(0, min(23 * 60 + 59, $minutes));

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
