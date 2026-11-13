<?php

namespace App\Livewire\Admin\Directory;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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

    protected function tableQuery(): Builder
    {
        return ScheduledTrip::query()
            ->with(['commuteOffer.driverProfile.user', 'commuteOffer.locations', 'commuteOffer.vehicle'])
            ->where('trip_date', $this->day())
            ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status))
            ->orderBy('departure_at')
            ->orderBy('id');
    }

    protected function filters(): array
    {
        return ['status' => $this->status, 'date' => $this->date];
    }

    protected function applyFilters(array $filters): void
    {
        $this->date = (string) ($filters['date'] ?? '');
        parent::applyFilters($filters);
    }

    protected function exportColumns(): array
    {
        return [
            'id' => fn (ScheduledTrip $trip) => $trip->id,
            'date' => fn (ScheduledTrip $trip) => $trip->trip_date?->format('Y-m-d'),
            'departs' => fn (ScheduledTrip $trip) => $trip->departure_at?->timezone('Africa/Cairo')->format('H:i'),
            'driver' => fn (ScheduledTrip $trip) => $trip->commuteOffer?->driverProfile?->user?->full_name,
            'seats_taken' => fn (ScheduledTrip $trip) => $trip->seats_taken,
            'seats_total' => fn (ScheduledTrip $trip) => $trip->seats_total,
            'status' => fn (ScheduledTrip $trip) => $trip->status->value,
        ];
    }

    public function render()
    {
        $day = $this->day();

        return view('livewire.admin.directory.trip-days', [
            'day' => $day,
            'trips' => $this->tableQuery()->paginate(25),
            'tabs' => $this->statuses(),
            ...$this->tableTools(),
        ])->title(__('admin/directory.trip_days.title'));
    }
}
