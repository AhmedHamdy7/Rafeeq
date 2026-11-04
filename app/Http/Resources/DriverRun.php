<?php

namespace App\Http\Resources;

use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Trip\Actions\SweepDelayedTripsAction;
use App\Domains\Trip\Support\TripSettings;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final class DriverRun
{
    public const string SCHEDULED = 'scheduled';

    public const string STARTING_SOON = 'starting_soon';

    public const string IN_PROGRESS = 'in_progress';

    public const string NOT_STARTED = 'not_started';

    public const string LATE = 'late';

    private const int DEADLINES_HORIZON_MINUTES = 180;

    public static function phase(ScheduledTrip $trip): string
    {
        if ($trip->status !== ScheduledTripStatus::Scheduled) {
            return self::IN_PROGRESS;
        }

        $departure = CarbonImmutable::instance($trip->departure_at);
        $now = CarbonImmutable::now();

        return match (true) {
            $now->addMinutes(TripSettings::startingSoonMinutes())->lessThan($departure) => self::SCHEDULED,
            $now->lessThan($departure) => self::STARTING_SOON,
            $now->lessThan($departure->addMinutes(SweepDelayedTripsAction::violationMinutes())) => self::NOT_STARTED,
            default => self::LATE,
        };
    }

    /**
     * @return array{promisedArrivalAt: string|null, promisedArrivalLocal: string|null}
     */
    public static function promise(ScheduledTrip $trip): array
    {
        return [
            'promisedArrivalAt' => $trip->promised_arrival_at?->toIso8601String(),
            'promisedArrivalLocal' => self::local($trip, $trip->promised_arrival_at),
        ];
    }

    /**
     * @return array{startedAt: string|null, startedLocal: string|null}
     */
    public static function started(ScheduledTrip $trip): array
    {
        $startedAt = $trip->tripSession?->started_at;

        return [
            'startedAt' => $startedAt?->toIso8601String(),
            'startedLocal' => self::local($trip, $startedAt),
        ];
    }

    /**
     * @return array{canStart: bool, startOpensAt: string|null, startOpensLocal: string|null}
     */
    public static function startWindow(ScheduledTrip $trip, bool $canStart): array
    {
        $opensAt = $trip->status === ScheduledTripStatus::Scheduled
            ? CarbonImmutable::instance($trip->departure_at)->subMinutes(TripSettings::startWindowMinutes())
            : null;

        return [
            'canStart' => $canStart,
            'startOpensAt' => $opensAt?->toIso8601String(),
            'startOpensLocal' => self::local($trip, $opensAt),
        ];
    }

    /**
     * @return array{warningAt: string, warningLocal: string, lateAt: string, lateLocal: string, expiresAt: string, expiresLocal: string}|null
     */
    public static function startDeadlines(ScheduledTrip $trip): ?array
    {
        $departure = CarbonImmutable::instance($trip->departure_at);

        if ($trip->status !== ScheduledTripStatus::Scheduled
            || CarbonImmutable::now()->addMinutes(self::DEADLINES_HORIZON_MINUTES)->lessThan($departure)) {
            return null;
        }

        $warning = $departure->addMinutes(SweepDelayedTripsAction::warningMinutes());
        $late = $departure->addMinutes(SweepDelayedTripsAction::violationMinutes());
        $expires = $departure->addMinutes(SweepDelayedTripsAction::expiryMinutes());

        return [
            'warningAt' => $warning->toIso8601String(),
            'warningLocal' => (string) self::local($trip, $warning),
            'lateAt' => $late->toIso8601String(),
            'lateLocal' => (string) self::local($trip, $late),
            'expiresAt' => $expires->toIso8601String(),
            'expiresLocal' => (string) self::local($trip, $expires),
        ];
    }

    /**
     * @return array{tripId: string, commuteId: string, originLabel: string|null, destinationLabel: string|null, departureAt: string, departureLocal: string, expiredAt: string|null, expiredLocal: string|null}
     */
    public static function expired(ScheduledTrip $trip): array
    {
        return [
            'tripId' => (string) $trip->id,
            'commuteId' => (string) $trip->commute_offer_id,
            ...JourneyCard::route($trip->commuteOffer),
            'departureAt' => $trip->departure_at->toIso8601String(),
            'departureLocal' => $trip->departure_local->format('Y-m-d H:i:s'),
            'expiredAt' => $trip->expired_at?->toIso8601String(),
            'expiredLocal' => self::local($trip, $trip->expired_at),
        ];
    }

    public static function expiredToday(ScheduledTrip $trip): bool
    {
        if ($trip->expired_at === null) {
            return false;
        }

        $timezone = self::timezone($trip);

        return CarbonImmutable::instance($trip->expired_at)->setTimezone($timezone)->format('Y-m-d')
            === CarbonImmutable::now($timezone)->format('Y-m-d');
    }

    private static function local(ScheduledTrip $trip, ?DateTimeInterface $at): ?string
    {
        return $at === null
            ? null
            : CarbonImmutable::instance($at)->setTimezone(self::timezone($trip))->format('Y-m-d H:i:s');
    }

    private static function timezone(ScheduledTrip $trip): string
    {
        return $trip->commuteSchedule?->timezone ?? (string) config('app.timezone');
    }
}
