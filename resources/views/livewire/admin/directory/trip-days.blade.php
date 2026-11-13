{{-- TRIP DAYS (Y). One day's runs, by departure time. Read-only. --}}
@php use App\Domains\Commute\Enums\CommuteLocationType; use App\Livewire\Admin\Directory\DirectoryPage; @endphp
<div>
    <x-admin.page-head :title="__('admin/directory.trip_days.title')" :sub="trans_choice('admin/directory.count', $trips->total(), ['count' => $trips->total()])">
        <label class="ad-row" style="gap:6px">
            <span class="ad-label">{{ __('admin/directory.trip_days.day') }}</span>
            <input type="date" class="ad-input" style="inline-size:auto" wire:model.live="date" value="{{ $day }}">
        </label>
    </x-admin.page-head>
    @include('livewire.admin.directory._tabs')
    <x-admin.card flush>
        @if ($trips->isEmpty())
            <x-admin.state kind="empty" :title="__('admin/directory.empty')" />
        @else
            <div class="ad-table-wrap">
                <table class="ad-table ad-table--cards">
                    <thead><tr>
                        <th>{{ __('admin/directory.departs') }}</th>
                        <th>{{ __('admin/directory.route') }}</th>
                        <th>{{ __('admin/directory.driver') }}</th>
                        <th class="ad-end">{{ __('admin/directory.seats') }}</th>
                        <th class="ad-end">{{ __('admin/directory.price') }}</th>
                        <th>{{ __('admin/directory.status') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($trips as $trip)
                        @php($offer = $trip->commuteOffer)
                        @php($origin = $offer?->locations->firstWhere('type', CommuteLocationType::Origin))
                        @php($destination = $offer?->locations->firstWhere('type', CommuteLocationType::Destination))
                        <tr wire:key="trip-day-{{ $trip->id }}">
                            <td data-label="{{ __('admin/directory.departs') }}" class="ad-num" style="font-weight:700"><a class="ad-link" href="{{ route('admin.trip-days.show', $trip->id) }}" wire:navigate>{{ $trip->departure_at?->timezone('Africa/Cairo')->format('H:i') }}</a></td>
                            <td data-label="{{ __('admin/directory.route') }}"><bdi>{{ $origin?->address ?? '—' }}</bdi> <x-admin.icon name="chevron" size="12" class="ad-flip ad-subtle" /> <bdi>{{ $destination?->address ?? '—' }}</bdi></td>
                            <td data-label="{{ __('admin/directory.driver') }}"><bdi>{{ $offer?->driverProfile?->user?->full_name ?? '—' }}</bdi></td>
                            <td data-label="{{ __('admin/directory.seats') }}" class="ad-end ad-num">{{ $trip->seats_taken }} / {{ $trip->seats_total }}</td>
                            <td data-label="{{ __('admin/directory.price') }}" class="ad-end ad-num">{{ $trip->seat_price_piastres === null ? '—' : 'EGP '.number_format($trip->seat_price_piastres / 100, 2) }}</td>
                            <td data-label="{{ __('admin/directory.status') }}"><span class="ad-chip ad-chip--{{ DirectoryPage::tone($trip->status->value) }}">{{ __('admin/directory.statuses.'.$trip->status->value) }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-admin.card>
    <x-rq-pager :page="$trips" />
</div>
