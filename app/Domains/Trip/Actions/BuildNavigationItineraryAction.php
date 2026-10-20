<?php

namespace App\Domains\Trip\Actions;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Trip\Enums\NavigationStopKind;
use App\Domains\Trip\Models\NavigationStop;
use App\Domains\Trip\Support\ItineraryStop;
use App\Domains\Trip\Support\NavigationItinerary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Keeps one day's navigation itinerary in step with who is actually confirmed on it
 * (Feature 03 v5 §3, §5).
 *
 * 🔴 **The write side bumps; the read side builds.** v5 says to recompute the itinerary on each
 * accepted booking, cancellation, approved pickup edit and completed drop-off. Doing that literally
 * — rebuilding inside the transaction that confirms a seat — would put a routing provider's latency
 * inside a row lock on `seats_taken`, which is the busiest lock in the system and the one the whole
 * concurrency design exists to hold for as short a time as possible. A provider timeout would stop
 * people booking seats.
 *
 * So {@see self::bump()} is one cheap UPDATE that says "this is out of date", safe to call inside
 * any transaction, and {@see self::forTrip()} rebuilds when somebody actually looks. Nothing is
 * lost: a stale itinerary is never served, because the version it was built from is compared on
 * every read. And nothing is wasted: an itinerary nobody opens is never paid for.
 *
 * 🔴 **A rebuild preserves `reached_at`.** That column is the driver's progress on a live run and
 * exists nowhere else — every other value here can be recomputed. A passenger confirmed mid-run
 * must not wipe the record of the three kerbs the driver has already pulled away from.
 */
final readonly class BuildNavigationItineraryAction
{
    public function __construct(private GeoQueryEngine $geo) {}

    /**
     * Mark a day's itinerary out of date.
     *
     * One statement, no provider call, no model events: safe to call from inside the transaction
     * that confirms or cancels a seat. `increment` rather than a read-modify-write, so two seats
     * confirmed at once cannot both read version 4 and both write 5 — which would leave the stored
     * itinerary looking current while missing a passenger.
     */
    public function bump(string $scheduledTripId): void
    {
        ScheduledTrip::query()->whereKey($scheduledTripId)->increment('itinerary_version');
    }

    /**
     * Bump every future day of a commute.
     *
     * For the changes that are not about one day — a route edited, a pickup point moved for a
     * recurring member — where naming the affected days individually would mean the caller
     * reimplementing "which days does this person ride".
     */
    public function bumpFutureDaysOf(string $commuteOfferId): void
    {
        ScheduledTrip::query()
            ->where('commute_offer_id', $commuteOfferId)
            ->where('departure_at', '>', now())
            ->increment('itinerary_version');
    }

    /**
     * The authoritative itinerary for one day, rebuilt if the stops have changed since it was last
     * built.
     *
     * @return Collection<int, NavigationStop>
     */
    public function forTrip(ScheduledTrip $trip): Collection
    {
        if ($trip->itinerary_built_version >= $trip->itinerary_version && $trip->itinerary_built_version > 0) {
            return NavigationStop::query()
                ->where('scheduled_trip_id', $trip->getKey())
                ->with('booking.passenger')
                ->orderBy('sequence')
                ->get();
        }

        return $this->rebuild($trip);
    }

    /**
     * What the itinerary WOULD look like with one more pair of points in it — the driver's route
     * preview for a pending request (v5 §5: `route-preview`, marked `PREVIEW_NOT_CONFIRMED`).
     *
     * 🔒 Returns a composed itinerary and writes nothing. v5 §2: *"Do not insert pending, rejected,
     * expired or unaccepted counteroffer locations into the official confirmed trip route."* The
     * guarantee is structural — there is no `save()` on what comes back — rather than a flag
     * somebody has to remember to check.
     */
    public function preview(ScheduledTrip $trip, Coordinate $pickup, ?string $pickupLabel, Coordinate $dropoff, ?string $dropoffLabel): NavigationItinerary
    {
        [$start, $startLabel, $end, $endLabel] = $this->driverEndpoints($trip);

        $points = $this->confirmedPassengerPoints($trip);

        /*
         * The pending pair carries a synthetic booking id, because the ordering needs to know the
         * two points belong to the SAME person to keep her pickup before her drop-off — and there
         * is no booking yet, which is the whole point of a preview.
         */
        $pendingId = ItineraryStop::PROPOSED_BOOKING_ID;

        $points[] = ['bookingId' => $pendingId, 'kind' => NavigationStopKind::PassengerPickup, 'point' => $pickup, 'label' => $pickupLabel, 'reachedAt' => null];
        $points[] = ['bookingId' => $pendingId, 'kind' => NavigationStopKind::PassengerDropoff, 'point' => $dropoff, 'label' => $dropoffLabel, 'reachedAt' => null];

        return NavigationItinerary::compose(
            $this->geo,
            $start,
            $startLabel,
            $end,
            $endLabel,
            $points,
            $trip->departure_at === null ? null : CarbonImmutable::parse($trip->departure_at),
        );
    }

    /**
     * @return Collection<int, NavigationStop>
     */
    private function rebuild(ScheduledTrip $trip): Collection
    {
        [$start, $startLabel, $end, $endLabel] = $this->driverEndpoints($trip);

        /*
         * Read BEFORE the transaction, with the version it answers for.
         *
         * Composing calls the routing provider once per leg, so it happens outside any transaction
         * — the same reasoning as the class note. The version is captured first so that a booking
         * confirmed while this was composing leaves `itinerary_built_version` behind
         * `itinerary_version`, and the next read rebuilds. Capturing it afterwards would stamp this
         * itinerary as current when it had already missed somebody.
         */
        $version = (int) $trip->itinerary_version;

        $itinerary = NavigationItinerary::compose(
            $this->geo,
            $start,
            $startLabel,
            $end,
            $endLabel,
            $this->confirmedPassengerPoints($trip),
            $trip->departure_at === null ? null : CarbonImmutable::parse($trip->departure_at),
        );

        return DB::transaction(function () use ($trip, $itinerary, $version): Collection {
            /*
             * 🔴 The driver's progress, read before the old rows are deleted and reapplied after.
             *
             * Keyed by booking and kind rather than by sequence: the whole reason for a rebuild is
             * that the order may have changed, so "stop 3 was reached" is meaningless while
             * "Mariam's pickup was reached" is exactly as true as it was a minute ago.
             */
            $reached = NavigationStop::query()
                ->where('scheduled_trip_id', $trip->getKey())
                ->whereNotNull('reached_at')
                ->get()
                ->mapWithKeys(fn (NavigationStop $stop): array => [
                    $stop->booking_id.':'.$stop->kind->value => $stop->reached_at,
                ]);

            NavigationStop::query()->where('scheduled_trip_id', $trip->getKey())->delete();

            foreach ($itinerary->stops as $stop) {
                $row = new NavigationStop;

                $row->forceFill([
                    ...$stop->columns(),
                    'id' => (string) Str::ulid(),
                    'scheduled_trip_id' => $trip->getKey(),
                    'itinerary_version' => $version,
                    'reached_at' => $reached[$stop->bookingId.':'.$stop->kind->value] ?? null,
                ])->save();
            }

            $trip->forceFill([
                'itinerary_built_version' => $version,
                'itinerary_built_at' => now(),
                'itinerary_polyline' => $itinerary->polyline,
                'itinerary_distance_meters' => $itinerary->distance->metres,
                'itinerary_duration_seconds' => $itinerary->durationSeconds,
            ])->save();

            return NavigationStop::query()
                ->where('scheduled_trip_id', $trip->getKey())
                ->with('booking.passenger')
                ->orderBy('sequence')
                ->get();
        });
    }

    /**
     * The driver's start and end, from the commute's own route.
     *
     * @return array{0: Coordinate, 1: string|null, 2: Coordinate, 3: string|null}
     */
    private function driverEndpoints(ScheduledTrip $trip): array
    {
        $trip->loadMissing('commuteOffer.locations');

        $offer = $trip->commuteOffer;

        if (! $offer instanceof CommuteOffer) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        $origin = $offer->locations->firstWhere('type', CommuteLocationType::Origin);
        $destination = $offer->locations->firstWhere('type', CommuteLocationType::Destination);

        if ($origin === null || $destination === null) {
            /*
             * v5 §1 makes the start and end required, and publishing already enforces it. A day
             * generated from a commute with no route is therefore not a thing that should exist —
             * and the honest answer is a refusal rather than an itinerary that begins nowhere.
             */
            throw DomainException::of(ErrorCode::CommuteRouteInvalid, fields: [
                'route' => [__('commute.route.origin_equals_destination')],
            ]);
        }

        return [
            new Coordinate((float) $origin->lat, (float) $origin->lng),
            $origin->address,
            new Coordinate((float) $destination->lat, (float) $destination->lng),
            $destination->address,
        ];
    }

    /**
     * Two points per confirmed booking on this day.
     *
     * 🔒 **Confirmed only.** v5 §4: *"the shared/official itinerary includes **confirmed** riders
     * only"*, and §2 forbids pending, rejected or expired points from reaching the official route.
     * A pending request's points are visible to the driver in her own request review and in the
     * preview above — nowhere else.
     *
     * @return list<array{bookingId: string, kind: NavigationStopKind, point: Coordinate, label: string|null, reachedAt: CarbonImmutable|null}>
     */
    private function confirmedPassengerPoints(ScheduledTrip $trip): array
    {
        $bookings = Booking::query()
            ->where('scheduled_trip_id', $trip->getKey())
            ->where('status', BookingStatus::Confirmed->value)
            // Ordered so the arrival order of equally-placed stops is stable between rebuilds.
            ->orderBy('id')
            ->get();

        $points = [];

        foreach ($bookings as $booking) {
            /*
             * A booking with no agreed points cannot be navigated to. It should not exist — the
             * points are required on every request and snapshotted at approval — but one can:
             * `bookings.seat_request_id` is `nullOnDelete`, so a booking whose request row was
             * deleted before the snapshot columns existed carries nothing. Skipping is the only
             * honest option; inventing a kerb for somebody to stand on is not.
             */
            if ($booking->pickup_lat === null || $booking->dropoff_lat === null) {
                continue;
            }

            $points[] = [
                'bookingId' => (string) $booking->getKey(),
                'kind' => NavigationStopKind::PassengerPickup,
                'point' => new Coordinate((float) $booking->pickup_lat, (float) $booking->pickup_lng),
                'label' => $booking->pickup_label,
                'reachedAt' => null,
            ];

            $points[] = [
                'bookingId' => (string) $booking->getKey(),
                'kind' => NavigationStopKind::PassengerDropoff,
                'point' => new Coordinate((float) $booking->dropoff_lat, (float) $booking->dropoff_lng),
                'label' => $booking->dropoff_label,
                'reachedAt' => null,
            ];
        }

        return $points;
    }
}
