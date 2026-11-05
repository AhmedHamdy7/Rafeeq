<?php

namespace App\Domains\Commute\Actions;

use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Enums\CommuteType;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Http\Resources\LocalisedLabel;
use Carbon\CarbonImmutable;

final readonly class RenewOrEndCommuteAction
{
    private const int RENEW_WITHIN_DAYS = 7;

    private const int RECENT_TRIP_DAYS = 30;

    private const int RENEWAL_MONTHS = 12;

    public function __construct(private ChangeCommuteStatusAction $status) {}

    public function renewIfDue(CommuteOffer $offer): bool
    {
        $today = CarbonImmutable::today();

        if ($offer->commute_type !== CommuteType::Recurring
            || $offer->schedule->end_date->greaterThan($today->addDays(self::RENEW_WITHIN_DAYS))) {
            return false;
        }

        $hadTrip = $offer->scheduledTrips()
            ->where('status', ScheduledTripStatus::Completed->value)
            ->whereDate('trip_date', '>=', $today->subDays(self::RECENT_TRIP_DAYS)->toDateString())
            ->exists();

        if (! $hadTrip) {
            return false;
        }

        $offer->schedule->forceFill([
            'end_date' => CarbonImmutable::parse($offer->schedule->end_date)->addMonthsNoOverflow(self::RENEWAL_MONTHS)->toDateString(),
        ])->save();

        return true;
    }

    public function end(CommuteOffer $offer): void
    {
        $offer->loadMissing(['locations', 'driverProfile.user']);

        $waiting = SeatRequest::query()
            ->where('commute_offer_id', $offer->id)
            ->where('status', SeatRequestStatus::Waitlisted->value)
            ->pluck('passenger_user_id')
            ->unique();

        $this->status->archive($offer, 'schedule_ended');

        $from = $this->label($offer, CommuteLocationType::Origin);
        $to = $this->label($offer, CommuteLocationType::Destination);
        $data = ['commuteId' => (string) $offer->id];

        $driver = $offer->driverProfile?->user;

        if ($driver !== null) {
            Notifier::send($driver, NotificationType::CommuteArchived, ['from' => $from, 'to' => $to], $data);
        }

        User::query()->whereIn('id', $waiting)->get()->each(
            fn (User $member) => Notifier::send($member, NotificationType::WaitlistedCommuteEnded, ['from' => $from, 'to' => $to], $data),
        );
    }

    private function label(CommuteOffer $offer, CommuteLocationType $type): string
    {
        $location = $offer->locations->firstWhere('type', $type);

        return $location === null ? '' : (string) LocalisedLabel::location($location);
    }
}
