<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Support\LiveTripBoard;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Safety\Support\EscortCoverage;
use App\Domains\Trip\Models\TripSession;
use App\Domains\Trip\Support\LiveLocationStore;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The LIVE TRIPS section: every run on the road right now, problem cars first.
 *
 * Read-only by design. The prototype puts a "Call" button on each row, and there is no
 * calling here yet: a staff member phoning a driver needs a masked number (Chapter 8
 * talks about masking contact for exactly this), and handing staff raw phone numbers on a
 * screen that lists every car in the city is the wrong first version of that feature.
 * What an operator CAN do from here is see the problem and, for a live alert, go to the
 * safety desk where the case is worked.
 *
 * "Track" opens the row to show the last position the car reported. Not the trail — the
 * trail is evidence for a dispute and is deliberately not served anywhere (see
 * TripLocationController) — just where it was last heard from, and how long ago.
 */
#[Layout('components.layouts.admin')]
class LiveTrips extends Component
{
    use WithPagination;

    #[Url(as: 'flagged')]
    public bool $flaggedOnly = false;

    #[Url(as: 'women')]
    public bool $womenOnly = false;

    public ?string $trackId = null;

    public function mount(): void
    {
        abort_unless(Auth::guard('admin')->user()?->can(AdminPermission::TripView->value) === true, 403);
    }

    /**
     * A filter change must start from page one: staying on page four of the unfiltered
     * list after narrowing it to six flagged cars shows an empty page and implies there
     * are no problems.
     */
    public function updatedFlaggedOnly(): void
    {
        $this->resetPage();
    }

    public function updatedWomenOnly(): void
    {
        $this->resetPage();
    }

    public function track(?string $id): void
    {
        $this->trackId = $this->trackId === $id ? null : $id;
    }

    public function render(LiveLocationStore $locations)
    {
        $page = LiveTripBoard::page(25, $this->flaggedOnly, $this->womenOnly);

        $tracked = $this->trackId === null
            ? null
            : $page->getCollection()->first(fn (TripSession $session) => $session->id === $this->trackId);

        return view('livewire.admin.live-trips', [
            'trips' => $page,
            'tracked' => $tracked,
            'position' => $tracked === null ? null : $locations->current($tracked->id),
            'riders' => $tracked === null ? collect() : $this->ridersOn($tracked),
            'escortCorridors' => EscortCoverage::activeCorridorIds(),
        ])->title(__('admin.trips.title'));
    }

    /**
     * Who is booked on the tracked run, by public first name only.
     *
     * 🔒 First names, not full names, phone numbers or meeting points: the board is about
     * the car. An operator who needs more about a person goes to that person's case, where
     * reading it is the job and is recorded.
     */
    private function ridersOn(TripSession $session)
    {
        return Booking::query()
            ->where('scheduled_trip_id', $session->scheduled_trip_id)
            ->where('status', BookingStatus::Confirmed->value)
            ->with('passenger')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Booking $booking) => $booking->passenger?->public_first_name ?? '—');
    }
}
