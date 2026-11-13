<?php

namespace App\Livewire\Admin\Directory;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\ScheduledTrip;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;

/**
 * TRIP DAY DETAIL (Y02, Y03): one run — when it was meant to leave and what happened, who was
 * on it, whether they turned up, and the money each seat carried (frozen on the day, PAY-44).
 * Read-only. The chat is not here: it opens only inside a report about the run (Y04).
 */
#[Layout('components.layouts.admin')]
class TripDayDetail extends RecordPage
{
    #[Locked]
    public string $tripId = '';

    public function mount(ScheduledTrip $trip): void
    {
        $this->authorizeTo(AdminPermission::TripView);
        $this->tripId = $trip->id;
    }

    public function render()
    {
        $trip = ScheduledTrip::query()
            ->whereKey($this->tripId)
            ->with(['commuteOffer.driverProfile.user', 'commuteOffer.vehicle', 'commuteOffer.locations', 'tripSession'])
            ->firstOrFail();

        $bookings = Booking::query()->where('scheduled_trip_id', $trip->id)->with(['passenger', 'attendance'])->orderBy('created_at')->get();

        $completed = $bookings->filter(fn (Booking $b) => $b->status->value === 'completed');

        // The timeline: the plan, then what the run did.
        $session = $trip->tripSession;
        $timeline = collect([
            ['at' => $trip->departure_at, 'key' => 'planned'],
            ['at' => $session?->started_at, 'key' => 'started'],
            ['at' => $session?->deviation_detected_at, 'key' => 'deviation'],
            ['at' => $session?->completed_at, 'key' => 'completed'],
            ['at' => $trip->status->value === 'cancelled' ? $trip->updated_at : null, 'key' => 'cancelled'],
        ])->filter(fn (array $step) => $step['at'] !== null)->sortBy('at')->values();

        return view('livewire.admin.directory.trip-day-detail', [
            'trip' => $trip,
            'bookings' => $bookings,
            'timeline' => $timeline,
            'money' => [
                'seats' => (int) $bookings->filter(fn (Booking $b) => in_array($b->status->value, ['confirmed', 'completed', 'no_show'], true))->sum('seats_reserved'),
                'gross' => (int) $completed->sum('price_snapshot_piastres'),
                'commission' => (int) $completed->sum('platform_fee_snapshot_piastres'),
                'driver' => (int) $completed->sum('driver_amount_snapshot_piastres'),
            ],
            'mayOpenMembers' => $this->may(AdminPermission::MemberView),
        ])->title(__('admin/records.trip.title'));
    }
}
