<?php

namespace App\Domains\Rating\Support;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Models\User;
use App\Domains\Rating\Actions\SubmitRatingAction;
use App\Domains\Rating\Models\Rating;
use App\Domains\Reliability\Enums\ReliabilityEventKind;
use App\Domains\Reliability\Models\ReliabilityEvent;
use App\Domains\Safety\Models\Incident;
use App\Domains\Trip\Enums\NavigationStopKind;
use App\Domains\Trip\Models\Attendance;
use App\Domains\Trip\Models\NavigationStop;
use App\Domains\Trip\Models\TripWaitTimer;
use App\Domains\Trip\Support\PickupTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class RatingPrompts
{
    public const string FIRST_RIDE = 'first_ride';

    public const string WEEKLY = 'weekly';

    public const string UNUSUAL = 'unusual';

    private const int WEEK_DAYS = 7;

    private const int CANDIDATES = 20;

    private const array RATEABLE = [BookingStatus::Completed, BookingStatus::EndedEarly];

    public function __construct(private SubmitRatingAction $ratings) {}

    public static function lateThresholdMinutes(): int
    {
        return (int) config('rafeeq.trip.driver_late_for_pickup_minutes');
    }

    public function for(User $user): ?array
    {
        $candidates = $this->candidates($user);

        $unusual = $candidates->first(fn (Booking $booking): bool => $booking->rating_prompted_at === null && $this->wasUnusual($booking));

        if ($unusual !== null) {
            return $this->prompt($unusual, self::UNUSUAL);
        }

        $shown = $candidates->first(fn (Booking $booking): bool => $booking->rating_prompted_at !== null);

        if ($shown !== null) {
            return $this->present($shown, $this->reasonFor($shown, $user) ?? self::WEEKLY);
        }

        foreach ($candidates as $booking) {
            $reason = $this->reasonFor($booking, $user);

            if ($reason !== null) {
                return $this->prompt($booking, $reason);
            }
        }

        return null;
    }

    private function prompt(Booking $booking, string $reason): array
    {
        $booking->forceFill(['rating_prompted_at' => now()])->save();

        return $this->present($booking, $reason);
    }

    public function dismiss(Booking $booking): void
    {
        if ($booking->rating_prompt_dismissed_at === null) {
            $booking->forceFill([
                'rating_prompt_dismissed_at' => now(),
                'rating_prompted_at' => $booking->rating_prompted_at ?? now(),
            ])->save();
        }
    }

    public function reasonFor(Booking $booking, User $user): ?string
    {
        if ($this->wasUnusual($booking)) {
            return self::UNUSUAL;
        }

        if ($this->isFirstWithDriver($booking, $user)) {
            return self::FIRST_RIDE;
        }

        $last = $this->lastTouchWithDriver($booking, $user);

        if ($last === null || $last->lessThanOrEqualTo(CarbonImmutable::now()->subDays(self::WEEK_DAYS))) {
            return self::WEEKLY;
        }

        return null;
    }

    public function wasUnusual(Booking $booking): bool
    {
        return $booking->status === BookingStatus::EndedEarly
            || $this->disputeOpened($booking)
            || $this->driverWasLate($booking);
    }

    public function driverWasLate(Booking $booking): bool
    {
        $recorded = ReliabilityEvent::query()
            ->where('scheduled_trip_id', $booking->scheduled_trip_id)
            ->where('user_id', $booking->driver_profile_id)
            ->where('kind', ReliabilityEventKind::DriverLate)
            ->exists();

        if ($recorded) {
            return true;
        }

        $promised = $booking->agreed_pickup_at !== null
            ? CarbonImmutable::instance($booking->agreed_pickup_at)
            : PickupTime::for($booking);

        $arrived = $this->arrivedAt($booking);

        if ($promised === null || $arrived === null) {
            return false;
        }

        return $arrived->greaterThan($promised->addMinutes(self::lateThresholdMinutes()));
    }

    private function arrivedAt(Booking $booking): ?CarbonImmutable
    {
        $times = collect([
            NavigationStop::query()
                ->where('scheduled_trip_id', $booking->scheduled_trip_id)
                ->where('booking_id', $booking->id)
                ->where('kind', NavigationStopKind::PassengerPickup)
                ->value('reached_at'),
            TripWaitTimer::query()->where('booking_id', $booking->id)->min('arrived_at'),
            Attendance::query()->where('booking_id', $booking->id)->value('checked_in_at'),
        ])->filter()->map(fn ($at): CarbonImmutable => CarbonImmutable::parse($at));

        return $times->isEmpty() ? null : $times->sort()->first();
    }

    private function disputeOpened(Booking $booking): bool
    {
        return Attendance::query()->where('booking_id', $booking->id)->whereNotNull('disputed_at')->exists()
            || Incident::query()->where('booking_id', $booking->id)->exists();
    }

    private function isFirstWithDriver(Booking $booking, User $user): bool
    {
        return ! Booking::query()
            ->join('scheduled_trips', 'scheduled_trips.id', '=', 'bookings.scheduled_trip_id')
            ->where('bookings.passenger_user_id', $user->id)
            ->where('bookings.driver_profile_id', $booking->driver_profile_id)
            ->whereIn('bookings.status', self::RATEABLE)
            ->whereKeyNot($booking->id)
            ->where('scheduled_trips.departure_at', '<', $booking->scheduledTrip->departure_at)
            ->exists();
    }

    private function lastTouchWithDriver(Booking $booking, User $user): ?CarbonImmutable
    {
        $prompts = Booking::query()
            ->where('passenger_user_id', $user->id)
            ->where('driver_profile_id', $booking->driver_profile_id)
            ->whereKeyNot($booking->id)
            ->selectRaw('MAX(rating_prompted_at) as prompted, MAX(rating_prompt_dismissed_at) as dismissed')
            ->first();

        $rated = Rating::query()
            ->join('bookings', 'bookings.id', '=', 'ratings.booking_id')
            ->join('scheduled_trips', 'scheduled_trips.id', '=', 'bookings.scheduled_trip_id')
            ->where('ratings.reviewer_user_id', $user->id)
            ->where('ratings.reviewed_user_id', $booking->driver_profile_id)
            ->where('ratings.booking_id', '!=', $booking->id)
            ->max('scheduled_trips.departure_at');

        $times = collect([$prompts?->prompted, $prompts?->dismissed, $rated])
            ->filter()
            ->map(fn ($at): CarbonImmutable => CarbonImmutable::parse($at));

        return $times->isEmpty() ? null : $times->sort()->last();
    }

    private function candidates(User $user): Collection
    {
        return Booking::query()
            ->join('scheduled_trips', 'scheduled_trips.id', '=', 'bookings.scheduled_trip_id')
            ->select('bookings.*')
            ->where('bookings.passenger_user_id', $user->id)
            ->whereIn('bookings.status', self::RATEABLE)
            ->whereNull('bookings.rating_prompt_dismissed_at')
            ->whereDoesntHave('ratings', fn (Builder $query) => $query->where('reviewer_user_id', $user->id))
            ->where('scheduled_trips.departure_at', '>=', now()->subDays(31))
            ->with(['scheduledTrip', 'driverProfile.user'])
            ->orderByDesc('scheduled_trips.departure_at')
            ->orderByDesc('bookings.id')
            ->limit(self::CANDIDATES)
            ->get()
            ->filter(fn (Booking $booking): bool => ! $this->ratings->deadlineFor($booking)->isPast())
            ->values();
    }

    private function present(Booking $booking, string $reason): array
    {
        $trip = $booking->scheduledTrip;

        return [
            'bookingId' => $booking->id,
            'tripId' => $booking->scheduled_trip_id,
            'driverName' => $booking->driverProfile?->user?->public_first_name,
            'tripDate' => $trip->departure_local?->format('Y-m-d'),
            'reason' => $reason,
        ];
    }
}
