<?php

namespace App\Livewire\Admin\Directory;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use Illuminate\Database\Eloquent\Builder;
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

    protected function tableQuery(): Builder
    {
        return Booking::query()
            ->with(['passenger', 'driverProfile.user', 'scheduledTrip'])
            ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status))
            ->latest('created_at')
            ->latest('id');
    }

    protected function exportColumns(): array
    {
        return [
            'id' => fn (Booking $booking) => $booking->id,
            'passenger' => fn (Booking $booking) => $booking->passenger?->full_name,
            'driver' => fn (Booking $booking) => $booking->driverProfile?->user?->full_name,
            'day' => fn (Booking $booking) => $booking->scheduledTrip?->trip_date?->format('Y-m-d'),
            'seats' => fn (Booking $booking) => $booking->seats_reserved,
            'status' => fn (Booking $booking) => $booking->status->value,
            'created_at' => fn (Booking $booking) => $booking->created_at?->timezone('Africa/Cairo')->format('Y-m-d H:i'),
        ];
    }

    public function render()
    {
        return view('livewire.admin.directory.bookings', [
            'bookings' => $this->tableQuery()->paginate(25),
            'tabs' => $this->statuses(),
            ...$this->tableTools(),
        ])->title(__('admin/directory.bookings.title'));
    }
}
