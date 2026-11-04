<?php

namespace Database\Seeders;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Enums\PointSource;
use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Booking\Enums\SeatRequestCommitment;
use App\Domains\Booking\Enums\SeatRequestKind;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\PickupPointRequest;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Identity\Enums\Capability;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserStat;
use App\Domains\Notification\Models\Conversation;
use App\Domains\Shared\ValueObjects\Coordinate;
use Illuminate\Support\Str;

final class DriverRequestsStatesSeeder extends DriverHomeStatesSeeder
{
    public const array REQUEST_STATES = [
        'REQ-EMPTY' => '+201055557301',
        'REQ-INBOX' => '+201055557302',
        'REQ-DECIDED' => '+201055557303',
        'REQ-BREAKS-PROMISE' => '+201055557304',
        'REQ-OVER-LIMIT' => '+201055557305',
        'REQ-RECURRING-SOME-FULL' => '+201055557306',
        'REQ-TRIAL-FULL' => '+201055557307',
        'WL-DAYS' => '+201055557311',
        'WL-OFFER-OPEN' => '+201055557312',
        'WL-OFFER-ACCEPTED' => '+201055557313',
    ];

    private const string OTHER_COMMUTE_DRIVER = '+201055557399';

    private const int GENEROUS_PROMISE_MINUTES = 180;

    private const array ALL_DAYS_MASK = ['requested_days_mask' => 127, 'commitment' => SeatRequestCommitment::Recurring];

    public function applyRequestStates(?string $only = null): array
    {
        $report = [];

        foreach (self::REQUEST_STATES as $state => $phone) {
            if ($only !== null && $only !== $state) {
                continue;
            }

            $this->applyRequestState($state, $phone);

            $report[] = "{$phone}  {$state}";
        }

        return $report;
    }

    private function applyRequestState(string $state, string $phone): void
    {
        $driver = $this->commuteDriver($state, $phone);
        $offer = CommuteOffer::query()->where('driver_profile_id', $driver->id)->with('locations', 'schedule')->orderBy('id')->firstOrFail();

        $this->clear($offer);
        $this->resetDriver($driver, $offer);
        $this->advertiseStops($offer);

        $days = ScheduledTrip::query()
            ->where('commute_offer_id', $offer->id)
            ->where('departure_at', '>', now())
            ->orderBy('departure_at')
            ->limit(3)
            ->get();

        foreach (ScheduledTrip::query()->where('commute_offer_id', $offer->id)->get() as $day) {
            $day->forceFill(['promised_arrival_at' => $day->departure_at->copy()->addMinutes(self::GENEROUS_PROMISE_MINUTES)])->save();
        }

        match ($state) {
            'REQ-INBOX' => $this->inbox($offer, $days[1]),
            'REQ-DECIDED' => $this->decided($offer, $days[1]),
            'REQ-BREAKS-PROMISE' => $this->breaksPromise($offer),
            'REQ-OVER-LIMIT' => $this->overLimit($offer, $days[1]),
            'REQ-RECURRING-SOME-FULL' => $this->recurringSomeFull($offer, $days[0]),
            'REQ-TRIAL-FULL' => $this->trialFull($offer, $days[1]),
            'WL-DAYS' => $this->waitingDays($offer, $days),
            'WL-OFFER-OPEN' => $this->offerOpen($offer, $days[1]),
            'WL-OFFER-ACCEPTED' => $this->offerAccepted($offer, $days[1]),
            default => null,
        };
    }

    private function inbox(CommuteOffer $offer, ScheduledTrip $day): void
    {
        $this->ask($offer, $this->rider('Rana', 21, 4.7, 32), self::ALL_DAYS_MASK);

        $this->ask($offer, $this->rider('Mona', 22), [
            'scheduled_trip_id' => $day->id,
            'requested_pickup_source' => PointSource::DriverSuggestion,
            'requested_pickup_suggestion_id' => $this->stop($offer, CommuteLocationType::Pickup)->id,
            'requested_pickup_label' => $this->stop($offer, CommuteLocationType::Pickup)->address,
        ]);

        $hana = $this->rider('Hana', 23, 4.9, 12);
        $this->ask($offer, $hana, ['scheduled_trip_id' => $day->id, 'kind' => SeatRequestKind::WaitlistApplication]);
        $this->seatOnAnotherCommute($hana);
    }

    private function decided(CommuteOffer $offer, ScheduledTrip $day): void
    {
        foreach ([
            SeatRequestStatus::Approved, SeatRequestStatus::Rejected, SeatRequestStatus::Withdrawn,
            SeatRequestStatus::Expired, SeatRequestStatus::ClosedDatePassed, SeatRequestStatus::CommuteArchived,
            SeatRequestStatus::Counteroffered, SeatRequestStatus::CounterofferDeclined, SeatRequestStatus::TermsChanged,
            SeatRequestStatus::Ended, SeatRequestStatus::Waitlisted,
        ] as $index => $status) {
            $request = $this->ask($offer, $this->rider(ucfirst(Str::camel($status->value)), 30 + $index, 4.5, 8), ['scheduled_trip_id' => $day->id]);

            $answered = ['status' => $status->value, 'responded_at' => now()->subHours($index + 1)];

            if ($status === SeatRequestStatus::Waitlisted) {
                $answered['waitlist_position'] = 1;
            }

            if ($status === SeatRequestStatus::Counteroffered) {
                $pickup = $this->stop($offer, CommuteLocationType::Pickup);
                $answered += [
                    'counteroffered_at' => now()->subHour(),
                    'counteroffer_pickup_point' => new Coordinate((float) $pickup->lat, (float) $pickup->lng),
                    'counteroffer_pickup_lat' => $pickup->lat,
                    'counteroffer_pickup_lng' => $pickup->lng,
                    'counteroffer_pickup_label' => $pickup->address,
                ];
            }

            $request->forceFill($answered)->save();
        }
    }

    private function breaksPromise(CommuteOffer $offer): void
    {
        $day = $this->moveFirstDay($offer, 180, true);
        $day->forceFill(['promised_arrival_at' => $day->departure_at->copy()->addMinute()])->save();

        $this->seatOnce($this->rider('Salma', 41, 4.8, 20), $day);
        $this->ask($offer, $this->rider('Rana', 21, 4.7, 32), ['scheduled_trip_id' => $day->id]);
    }

    private function overLimit(CommuteOffer $offer, ScheduledTrip $day): void
    {
        $far = $this->along($offer, 0.5, 0.25);

        $this->ask($offer, $this->rider('Rana', 21, 4.7, 32), [
            'scheduled_trip_id' => $day->id,
            'requested_pickup_point' => $far,
            'requested_pickup_lat' => $far->lat,
            'requested_pickup_lng' => $far->lng,
            'requested_pickup_label' => 'Far from the route',
        ]);
    }

    private function recurringSomeFull(CommuteOffer $offer, ScheduledTrip $fullDay): void
    {
        $fullDay->forceFill(['seats_taken' => $fullDay->seats_total])->save();

        $this->ask($offer, $this->rider('Rana', 21, 4.7, 32), self::ALL_DAYS_MASK);
    }

    private function trialFull(CommuteOffer $offer, ScheduledTrip $day): void
    {
        $day->forceFill(['seats_taken' => $day->seats_total])->save();

        $this->ask($offer, $this->rider('Rana', 21, 4.7, 32), ['scheduled_trip_id' => $day->id]);
    }

    private function waitingDays(CommuteOffer $offer, mixed $days): void
    {
        $days[0]->forceFill(['seats_taken' => $days[0]->seats_total])->save();

        $this->wait($offer, $this->rider('Laila', 51, 4.9, 15), 1, ['scheduled_trip_id' => $days[0]->id]);
        $this->wait($offer, $this->rider('Mona', 22), 2, ['scheduled_trip_id' => $days[1]->id]);
        $this->wait($offer, $this->rider('Youssra', 52, 4.5, 6), 3, [
            ...self::ALL_DAYS_MASK,
            'unavailable_on' => now($offer->schedule->timezone)->toDateString(),
        ]);
        $this->seatOnAnotherCommute($this->rider('Youssra', 52, 4.5, 6));
    }

    private function offerOpen(CommuteOffer $offer, ScheduledTrip $day): void
    {
        $laila = $this->wait($offer, $this->rider('Laila', 51, 4.9, 15), 1, ['scheduled_trip_id' => $day->id]);
        $mona = $this->wait($offer, $this->rider('Mona', 22), 2, ['scheduled_trip_id' => $day->id]);

        $this->offerRound($day, [$laila->id => SeatOfferStatus::Open, $mona->id => SeatOfferStatus::Open]);
    }

    private function offerAccepted(CommuteOffer $offer, ScheduledTrip $day): void
    {
        $rider = $this->rider('Laila', 51, 4.9, 15);
        $laila = $this->wait($offer, $rider, 1, ['scheduled_trip_id' => $day->id]);
        $mona = $this->wait($offer, $this->rider('Mona', 22), 2, ['scheduled_trip_id' => $day->id]);

        $this->offerRound($day, [$laila->id => SeatOfferStatus::Accepted, $mona->id => SeatOfferStatus::TakenByOther]);

        $laila->forceFill(['status' => SeatRequestStatus::Approved->value, 'waitlist_position' => null])->save();
        $this->seatOnce($rider, $day);
    }

    private function offerRound(ScheduledTrip $day, array $statuses): void
    {
        $release = (string) Str::ulid();

        foreach ($statuses as $seatRequestId => $status) {
            $offer = new SeatOffer;
            $offer->forceFill([
                'scheduled_trip_id' => $day->id,
                'seat_request_id' => $seatRequestId,
                'status' => $status->value,
                'release_event_id' => $release,
                'closed_at' => $status === SeatOfferStatus::Open ? null : now(),
            ])->save();
        }
    }

    private function ask(CommuteOffer $offer, User $rider, array $overrides = []): SeatRequest
    {
        $pickup = $this->along($offer, 0.3, 0.002);
        $dropoff = $this->along($offer, 0.8, 0.002);

        return SeatRequest::factory()->create([
            'passenger_user_id' => $rider->id,
            'commute_offer_id' => $offer->id,
            'status' => SeatRequestStatus::Pending,
            'kind' => SeatRequestKind::SeatRequest,
            'requested_pickup_point' => $pickup,
            'requested_pickup_lat' => $pickup->lat,
            'requested_pickup_lng' => $pickup->lng,
            'requested_pickup_label' => 'Al Rehab Mall entrance',
            'requested_pickup_source' => PointSource::CustomMap,
            'requested_dropoff_point' => $dropoff,
            'requested_dropoff_lat' => $dropoff->lat,
            'requested_dropoff_lng' => $dropoff->lng,
            'requested_dropoff_label' => 'Smart Village B12',
            'requested_dropoff_source' => PointSource::CustomMap,
            ...$overrides,
        ]);
    }

    private function wait(CommuteOffer $offer, User $rider, int $position, array $overrides = []): SeatRequest
    {
        return $this->ask($offer, $rider, [
            'status' => SeatRequestStatus::Waitlisted,
            'waitlist_position' => $position,
            'responded_at' => now()->subDays($position),
            ...$overrides,
        ]);
    }

    private function rider(string $first, int $suffix, ?float $rating = null, int $trips = 0): User
    {
        $rider = $this->person('+2010555574'.str_pad((string) $suffix, 2, '0', STR_PAD_LEFT), $first, [Capability::Passenger]);

        (UserStat::query()->where('user_id', $rider->id)->first() ?? new UserStat)->forceFill([
            'user_id' => $rider->id,
            'avg_rating_as_passenger' => $rating,
            'rating_count_as_passenger' => $rating === null ? 0 : $trips,
            'completed_trips_as_passenger' => $trips,
        ])->save();

        return $rider;
    }

    private function seatOnAnotherCommute(User $rider): void
    {
        $driver = $this->commuteDriver('REQ-OTHER', self::OTHER_COMMUTE_DRIVER);
        $offer = CommuteOffer::query()->where('driver_profile_id', $driver->id)->orderBy('id')->firstOrFail();

        $day = ScheduledTrip::query()
            ->where('commute_offer_id', $offer->id)
            ->where('departure_at', '>', now())
            ->orderBy('departure_at')
            ->with('commuteOffer')
            ->firstOrFail();

        $seated = Booking::query()
            ->where('scheduled_trip_id', $day->id)
            ->where('passenger_user_id', $rider->id)
            ->where('status', BookingStatus::Confirmed->value)
            ->exists();

        if (! $seated) {
            $this->seatOnce($rider, $day);
        }
    }

    private function advertiseStops(CommuteOffer $offer): void
    {
        foreach ([
            [CommuteLocationType::Pickup, 'Rehab Gate 2', 0.2, 1],
            [CommuteLocationType::Pickup, 'Al Rehab Mall service road', 0.35, 2],
            [CommuteLocationType::Dropoff, 'Smart Village B6', 0.85, 3],
        ] as [$type, $address, $share, $sequence]) {
            $point = $this->along($offer, $share);

            CommuteLocation::query()->updateOrCreate(
                ['commute_offer_id' => $offer->id, 'type' => $type, 'sequence' => $sequence],
                ['point' => $point, 'lat' => $point->lat, 'lng' => $point->lng, 'address' => $address, 'is_exact' => true],
            );
        }

        $offer->load('locations');
    }

    private function stop(CommuteOffer $offer, CommuteLocationType $type): CommuteLocation
    {
        return $offer->locations->where('type', $type)->sortBy('sequence')->firstOrFail();
    }

    private function along(CommuteOffer $offer, float $share, float $offsetLat = 0.0): Coordinate
    {
        $origin = $offer->locations->firstWhere('type', CommuteLocationType::Origin);
        $destination = $offer->locations->firstWhere('type', CommuteLocationType::Destination);

        return new Coordinate(
            (float) $origin->lat + ((float) $destination->lat - (float) $origin->lat) * $share + $offsetLat,
            (float) $origin->lng + ((float) $destination->lng - (float) $origin->lng) * $share,
        );
    }

    private function clear(CommuteOffer $offer): void
    {
        $requests = SeatRequest::query()->where('commute_offer_id', $offer->id)->pluck('id');

        SeatOffer::query()->whereIn('seat_request_id', $requests)->delete();
        PickupPointRequest::query()->whereIn('seat_request_id', $requests)->delete();
        Conversation::query()->whereIn('seat_request_id', $requests)->delete();
        Booking::query()->whereIn('seat_request_id', $requests)->update(['seat_request_id' => null]);
        SeatRequest::query()->whereIn('id', $requests)->delete();
    }
}
