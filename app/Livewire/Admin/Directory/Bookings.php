<?php

namespace App\Livewire\Admin\Directory;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use Livewire\Attributes\Layout;

/** BOOKINGS (B): every seat booked, newest first, by status. */
#[Layout('components.layouts.admin')]
class Bookings extends DirectoryPage
{
    protected function permission(): AdminPermission
    {
        return AdminPermission::CommuteView;
    }

    protected function statuses(): array
    {
        return array_map(fn (BookingStatus $status) => $status->value, BookingStatus::cases());
    }

    public function render()
    {
        return view('livewire.admin.directory.bookings', [
            'bookings' => Booking::query()
                ->with(['passenger', 'driverProfile.user', 'scheduledTrip'])
                ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status))
                ->latest('created_at')
                ->latest('id')
                ->paginate(25),
            'tabs' => $this->statuses(),
        ])->title(__('admin/directory.bookings.title'));
    }
}
