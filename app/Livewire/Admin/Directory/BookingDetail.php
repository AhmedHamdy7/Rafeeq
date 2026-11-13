<?php

namespace App\Livewire\Admin\Directory;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Booking\Actions\CancelBookingAction;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Trip\Models\MemberWarning;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;

/**
 * BOOKING DETAIL (B03, B04): the seat, its price as frozen on the day, what happened to it and
 * when, and any fee or warning it led to and why.
 *
 * Staff can cancel a seat for the member (B04). It is cancelled as the system — no fee and
 * nothing on anybody's reliability record, the same as a cancellation forced by a suspension —
 * and the seat goes to the waiting list. The reason is kept in the audit log.
 */
#[Layout('components.layouts.admin')]
class BookingDetail extends RecordPage
{
    #[Locked]
    public string $bookingId = '';

    public bool $cancelling = false;

    public function mount(Booking $booking): void
    {
        $this->authorizeTo(AdminPermission::CommuteView);
        $this->bookingId = $booking->id;
    }

    public function startCancel(): void
    {
        $this->authorizeTo(AdminPermission::CommuteModerate);
        $this->resetValidation();
        $this->reset('reason', 'done');
        $this->cancelling = true;
    }

    public function cancel(CancelBookingAction $bookings): void
    {
        $this->authorizeTo(AdminPermission::CommuteModerate);
        $this->requireReason();

        $booking = Booking::query()->whereKey($this->bookingId)->firstOrFail();
        $from = $booking->status->value;

        DB::transaction(function () use ($bookings, $booking, $from): void {
            $bookings->bySuspension($booking, 'staff_cancelled');
            AdminActionLog::record($this->admin(), 'booking.cancel', $booking, ['status' => $from], ['status' => $booking->refresh()->status->value], $this->reason);
        });

        $this->reset('cancelling', 'reason');
        $this->done = __('admin/records.booking.cancelled');
    }

    public function render()
    {
        $booking = Booking::query()
            ->whereKey($this->bookingId)
            ->with(['passenger', 'driverProfile.user', 'scheduledTrip.commuteOffer.locations', 'attendance', 'events'])
            ->firstOrFail();

        return view('livewire.admin.directory.booking-detail', [
            'booking' => $booking,
            'warnings' => MemberWarning::query()->where('booking_id', $booking->id)->orderBy('created_at')->get(),
            'entries' => $this->may(AdminPermission::PaymentView) ? DebtEntry::query()->where('booking_id', $booking->id)->orderBy('created_at')->get() : null,
            'history' => $this->staffActions($booking->id),
            'mayCancel' => $this->may(AdminPermission::CommuteModerate) && $booking->status->canTransitionTo(BookingStatus::CancelledByPassenger),
            'mayOpenMembers' => $this->may(AdminPermission::MemberView),
        ])->title(__('admin/records.booking.title'));
    }
}
