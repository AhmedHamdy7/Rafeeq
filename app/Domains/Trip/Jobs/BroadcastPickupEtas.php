<?php

namespace App\Domains\Trip\Jobs;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Trip\Actions\BuildNavigationItineraryAction;
use App\Domains\Trip\Actions\RecordTripLocationAction;
use App\Domains\Trip\Events\PickupEtaUpdated;
use App\Domains\Trip\Models\TripSession;
use App\Domains\Trip\Support\LiveStopEtas;
use App\Domains\Trip\Support\PassengerPickupEta;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BroadcastPickupEtas implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $uniqueFor = 5;

    public function __construct(public string $tripSessionId) {}

    public function uniqueId(): string
    {
        return $this->tripSessionId;
    }

    public function handle(RecordTripLocationAction $locations, BuildNavigationItineraryAction $itinerary): void
    {
        try {
            $this->broadcast($locations, $itinerary);
        } catch (Throwable $e) {
            Log::warning('trip.pickup_eta.broadcast_failed', ['tripSessionId' => $this->tripSessionId, 'reason' => $e->getMessage()]);
        }
    }

    private function broadcast(RecordTripLocationAction $locations, BuildNavigationItineraryAction $itinerary): void
    {
        $session = TripSession::query()->with('scheduledTrip.commuteOffer')->find($this->tripSessionId);

        if ($session === null || ! $session->current_status->isUnderway() || $session->scheduledTrip === null) {
            return;
        }

        $trip = $session->scheduledTrip->setRelation('tripSession', $session);

        $bookings = Booking::query()
            ->where('scheduled_trip_id', $trip->id)
            ->where('status', BookingStatus::Confirmed)
            ->get();

        if ($bookings->isEmpty()) {
            return;
        }

        $stops = $itinerary->forTrip($trip);
        $live = LiveStopEtas::at($trip, $stops, $locations->current($session));

        foreach ($bookings as $booking) {
            $eta = PassengerPickupEta::view($booking, $stops, $live);

            if ($eta['pickupEtaAt'] === null && $eta['stopsBeforeYou'] === null) {
                continue;
            }

            PickupEtaUpdated::dispatch(
                (string) $booking->passenger_user_id,
                (string) $trip->id,
                (string) $session->id,
                (string) $booking->id,
                $eta,
            );
        }
    }
}
