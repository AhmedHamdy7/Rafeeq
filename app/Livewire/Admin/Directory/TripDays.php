<?php

namespace App\Livewire\Admin\Directory;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;

/** TRIP DAYS (Y): the runs of one day (Cairo), by departure time and status. */
#[Layout('components.layouts.admin')]
class TripDays extends DirectoryPage
{
    #[Url(as: 'date')]
    public string $date = '';

    protected function permission(): AdminPermission
    {
        return AdminPermission::TripView;
    }

    protected function statuses(): array
    {
        return array_map(fn (ScheduledTripStatus $status) => $status->value, ScheduledTripStatus::cases());
    }

    public function updatedDate(): void
    {
        $this->resetPage();
    }

    private function day(): string
    {
        try {
            return $this->date === '' ? CarbonImmutable::now('Africa/Cairo')->toDateString() : CarbonImmutable::parse($this->date)->toDateString();
        } catch (\Throwable) {
            return CarbonImmutable::now('Africa/Cairo')->toDateString();
        }
    }

    public function render()
    {
        $day = $this->day();

        return view('livewire.admin.directory.trip-days', [
            'day' => $day,
            'trips' => ScheduledTrip::query()
                ->with(['commuteOffer.driverProfile.user', 'commuteOffer.locations', 'commuteOffer.vehicle'])
                ->where('trip_date', $day)
                ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status))
                ->orderBy('departure_at')
                ->orderBy('id')
                ->paginate(25),
            'tabs' => $this->statuses(),
        ])->title(__('admin/directory.trip_days.title'));
    }
}
