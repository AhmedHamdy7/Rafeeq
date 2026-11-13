<?php

namespace App\Livewire\Admin\Directory;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Booking\Models\SeatOffer;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;

/**
 * WAITING LISTS AND SEAT OFFERS (B05): every seat freed and offered to somebody on a waiting
 * list, and what became of it. Read-only.
 */
#[Layout('components.layouts.admin')]
class SeatOffers extends DirectoryPage
{
    protected function permission(): AdminPermission
    {
        return AdminPermission::CommuteView;
    }

    protected function statuses(): array
    {
        return array_map(fn (SeatOfferStatus $status) => $status->value, SeatOfferStatus::cases());
    }

    protected function tableQuery(): Builder
    {
        return SeatOffer::query()
            ->with(['seatRequest.passenger', 'scheduledTrip.commuteOffer.driverProfile.user'])
            ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status))
            ->latest('created_at')
            ->latest('id');
    }

    protected function exportColumns(): array
    {
        return [
            'id' => fn (SeatOffer $offer) => $offer->id,
            'passenger' => fn (SeatOffer $offer) => $offer->seatRequest?->passenger?->full_name,
            'driver' => fn (SeatOffer $offer) => $offer->scheduledTrip?->commuteOffer?->driverProfile?->user?->full_name,
            'day' => fn (SeatOffer $offer) => $offer->scheduledTrip?->trip_date?->format('Y-m-d'),
            'status' => fn (SeatOffer $offer) => $offer->status instanceof SeatOfferStatus ? $offer->status->value : (string) $offer->status,
            'created_at' => fn (SeatOffer $offer) => $offer->created_at?->timezone('Africa/Cairo')->format('Y-m-d H:i'),
        ];
    }

    public function render()
    {
        return view('livewire.admin.directory.seat-offers', [
            'offers' => $this->tableQuery()->paginate(25),
            'tabs' => $this->statuses(),
            ...$this->tableTools(),
        ])->title(__('admin/directory.seat_offers.title'));
    }
}
