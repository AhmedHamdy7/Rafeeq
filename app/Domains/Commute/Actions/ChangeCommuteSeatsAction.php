<?php

namespace App\Domains\Commute\Actions;

use App\Domains\Booking\Actions\OfferSeatToWaitlistAction;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteWeekdaySchedule;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\DaysMask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class ChangeCommuteSeatsAction
{
    public const string ONLY_THIS_DAY = 'only_this_occurrence';

    public const string SAME_WEEKDAY = 'all_future_on_this_weekday';

    public const string ALL_FUTURE_DAYS = 'all_future_days';

    public const array SCOPES = [self::ONLY_THIS_DAY, self::SAME_WEEKDAY, self::ALL_FUTURE_DAYS];

    public function __construct(private OfferSeatToWaitlistAction $offerSeat) {}

    public function execute(CommuteOffer $offer, int $seatsTotal, string $scope, ?string $tripId): array
    {
        if (! in_array($offer->status, [CommuteOfferStatus::Published, CommuteOfferStatus::Paused], true)) {
            throw DomainException::of(ErrorCode::CommuteNotEditable, fields: [
                'status' => [strtoupper($offer->status->value)],
            ]);
        }

        CreateCommuteOfferAction::assertSeatsFitVehicle($offer->vehicle, $seatsTotal);

        $first = $tripId === null ? null : $this->day($offer, $tripId);

        return DB::transaction(function () use ($offer, $seatsTotal, $scope, $first): array {
            $days = $this->days($offer, $scope, $first)->orderBy('id')->lockForUpdate()->get();
            $mostBooked = (int) $days->max('seats_taken');

            if ($seatsTotal < $mostBooked) {
                throw DomainException::of(ErrorCode::CommuteSeatsConflict, fields: [
                    'seatsTotal' => [(string) $mostBooked],
                ]);
            }

            $this->rememberForLaterDays($offer, $seatsTotal, $scope, $first);

            foreach ($days as $day) {
                $added = $seatsTotal - (int) $day->seats_total;

                $day->forceFill(['seats_total' => $seatsTotal])->save();

                if ($added > 0 && $offer->status === CommuteOfferStatus::Published) {
                    $this->offerSeat->forTrip($day, $added);
                }
            }

            $offer->touch();

            return $days->sortBy('departure_at')->pluck('id')->values()->all();
        });
    }

    private function day(CommuteOffer $offer, string $tripId): ScheduledTrip
    {
        return ScheduledTrip::query()
            ->where('commute_offer_id', $offer->getKey())
            ->whereKey($tripId)
            ->where('status', ScheduledTripStatus::Scheduled->value)
            ->where('departure_at', '>', now())
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }

    private function days(CommuteOffer $offer, string $scope, ?ScheduledTrip $first): mixed
    {
        if ($scope === self::ONLY_THIS_DAY) {
            return ScheduledTrip::query()->whereKey($first->getKey());
        }

        return ScheduledTrip::query()
            ->where('commute_offer_id', $offer->getKey())
            ->where('status', ScheduledTripStatus::Scheduled->value)
            ->where('departure_at', '>', now())
            ->when($scope === self::SAME_WEEKDAY, fn ($query) => $query
                ->whereDate('trip_date', '>=', $first->trip_date)
                ->whereRaw('WEEKDAY(trip_date) = WEEKDAY(?)', [$first->trip_date->toDateString()]));
    }

    private function rememberForLaterDays(CommuteOffer $offer, int $seatsTotal, string $scope, ?ScheduledTrip $first): void
    {
        if ($scope === self::ALL_FUTURE_DAYS) {
            $offer->forceFill(['seats_total' => $seatsTotal])->save();
            CommuteWeekdaySchedule::query()->where('commute_offer_id', $offer->getKey())->update(['seats_total' => null]);

            return;
        }

        if ($scope === self::SAME_WEEKDAY) {
            CommuteWeekdaySchedule::query()
                ->where('commute_offer_id', $offer->getKey())
                ->where('weekday_bit', DaysMask::bitForDate(CarbonImmutable::parse($first->trip_date)))
                ->update(['seats_total' => $seatsTotal]);
        }
    }
}
