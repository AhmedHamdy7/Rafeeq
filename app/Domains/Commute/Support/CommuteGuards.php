<?php

namespace App\Domains\Commute\Support;

use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Enums\CommuteRuleKey;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteRule;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Enums\Gender;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;

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
}
