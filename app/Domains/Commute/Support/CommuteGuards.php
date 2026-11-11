<?php

namespace App\Domains\Commute\Support;

use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\CommuteRuleKey;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteRule;
use App\Domains\Commute\Models\CommuteWeekdaySchedule;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Enums\Gender;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\DaysMask;

final class CommuteGuards
{
    public static function assertDriverMayUseAudience(string $driverUserId, CommuteAudience|string|null $audience): void
    {
        $audience = is_string($audience) ? CommuteAudience::tryFrom($audience) : $audience;

        if ($audience !== CommuteAudience::WomenOnly) {
            return;
        }

        if (User::query()->whereKey($driverUserId)->first()?->gender !== Gender::Woman) {
            throw DomainException::of(ErrorCode::CommuteWomenOnlyNotAllowed, fields: [
                'audience' => [__('errors.COMMUTE_WOMEN_ONLY_NOT_ALLOWED')],
            ]);
        }
    }

    public static function assertCarAllowsAc(?Vehicle $vehicle, bool $wantsAc): void
    {
        if ($wantsAc && self::carHasNoAc($vehicle)) {
            throw DomainException::of(ErrorCode::CommuteAcNotAvailable, fields: [
                'rules.ac' => [__('errors.COMMUTE_AC_NOT_AVAILABLE')],
            ]);
        }
    }

    public static function assertOfferFitsCarAc(CommuteOffer $offer, ?Vehicle $vehicle): void
    {
        self::assertCarAllowsAc($vehicle, $offer->rules()
            ->where('rule_key', CommuteRuleKey::Ac->value)
            ->where('rule_value', true)
            ->exists());
    }

    public static function clearAcOnOffer(CommuteOffer $offer, ?Vehicle $vehicle): void
    {
        if (self::carHasNoAc($vehicle)) {
            $offer->rules()->where('rule_key', CommuteRuleKey::Ac->value)->delete();
        }
    }

    public static function clearAcOnVehicleCommutes(Vehicle $vehicle): void
    {
        if (! self::carHasNoAc($vehicle)) {
            return;
        }

        CommuteRule::query()
            ->where('rule_key', CommuteRuleKey::Ac->value)
            ->whereIn('commute_offer_id', CommuteOffer::query()->where('vehicle_id', $vehicle->id)->select('id'))
            ->delete();
    }

    private static function carHasNoAc(?Vehicle $vehicle): bool
    {
        return $vehicle !== null && $vehicle->approved_seats !== null && ! $vehicle->has_ac;
    }

    public static function assertNoOverlap(CommuteOffer $offer): void
    {
        $gap = (int) config('rafeeq.commute.min_gap_between_commutes_minutes', 30);
        $offer->loadMissing(['schedule', 'weekdaySchedules']);

        if ($offer->schedule === null) {
            return;
        }

        $others = CommuteOffer::query()
            ->where('driver_profile_id', $offer->driver_profile_id)
            ->whereKeyNot($offer->getKey())
            ->whereIn('status', [CommuteOfferStatus::Published->value, CommuteOfferStatus::Paused->value])
            ->whereHas('schedule', fn ($query) => $query
                ->where('start_date', '<=', $offer->schedule->end_date)
                ->where('end_date', '>=', $offer->schedule->start_date))
            ->with('weekdaySchedules')
            ->get();

        foreach ($offer->weekdaySchedules as $day) {
            [$start, $end] = self::window($day);

            foreach ($others as $other) {
                $clash = $other->weekdaySchedules->firstWhere('weekday_bit', $day->weekday_bit);

                if ($clash === null) {
                    continue;
                }

                [$otherStart, $otherEnd] = self::window($clash);

                if ($start < $otherEnd + $gap && $otherStart < $end + $gap) {
                    throw DomainException::of(ErrorCode::CommuteOverlaps, fields: [
                        'commuteId' => [(string) $other->getKey()],
                        'weekday' => [self::WEEKDAYS[(int) $day->weekday_bit] ?? (string) $day->weekday_bit],
                        'minGapMinutes' => [(string) $gap],
                    ]);
                }
            }
        }
    }

    private const array WEEKDAYS = [
        DaysMask::SATURDAY => 'SATURDAY',
        DaysMask::SUNDAY => 'SUNDAY',
        DaysMask::MONDAY => 'MONDAY',
        DaysMask::TUESDAY => 'TUESDAY',
        DaysMask::WEDNESDAY => 'WEDNESDAY',
        DaysMask::THURSDAY => 'THURSDAY',
        DaysMask::FRIDAY => 'FRIDAY',
    ];

    /**
     * @return array{0: int, 1: int}
     */
    private static function window(CommuteWeekdaySchedule $day): array
    {
        $start = self::minutes((string) $day->departure_time);
        $end = $day->promised_arrival_time !== null ? self::minutes((string) $day->promised_arrival_time) : $start;

        return [$start, max($start, $end)];
    }

    private static function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', array_slice(explode(':', $time), 0, 2));

        return $hours * 60 + $minutes;
    }
}
