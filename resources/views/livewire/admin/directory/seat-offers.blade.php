{{-- WAITING LISTS AND SEAT OFFERS (B05). Read-only. --}}
@php use App\Livewire\Admin\Directory\DirectoryPage; @endphp
<div>
    <x-admin.page-head :title="__('admin/directory.bookings.title')" :sub="trans_choice('admin/directory.count', $offers->total(), ['count' => $offers->total()])" />
    @include('livewire.admin.directory._bookings-nav')
    @include('livewire.admin.directory._tabs')
    <x-admin.card flush>
        @if ($offers->isEmpty())
            <x-admin.state kind="empty" :title="__('admin/directory.empty')" />
        @else
            <div class="ad-table-wrap"><table class="ad-table ad-table--cards">
                <thead><tr>
                    <th>{{ __('admin/directory.passenger') }}</th>
                    <th>{{ __('admin/directory.driver') }}</th>
                    <th>{{ __('admin/directory.day') }}</th>
                    <th>{{ __('admin/directory.status') }}</th>
                    <th>{{ __('admin/directory.created') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($offers as $offer)
                    <tr wire:key="so-{{ $offer->id }}">
                        <td data-label="{{ __('admin/directory.passenger') }}"><bdi>{{ $offer->seatRequest?->passenger?->full_name ?? '—' }}</bdi></td>
                        <td data-label="{{ __('admin/directory.driver') }}"><bdi>{{ $offer->scheduledTrip?->commuteOffer?->driverProfile?->user?->full_name ?? '—' }}</bdi></td>
                        <td data-label="{{ __('admin/directory.day') }}" class="ad-num">@if ($offer->scheduledTrip)<a class="ad-link" href="{{ route('admin.trip-days.show', $offer->scheduledTrip->id) }}" wire:navigate>{{ $offer->scheduledTrip->trip_date?->format('D j M') }}</a>@else — @endif</td>
                        <td data-label="{{ __('admin/directory.status') }}"><span class="ad-chip ad-chip--{{ DirectoryPage::tone($offer->status->value) }}">{{ __('admin/directory.statuses.'.$offer->status->value) }}</span></td>
                        <td data-label="{{ __('admin/directory.created') }}" class="ad-muted ad-num">{{ $offer->created_at?->timezone('Africa/Cairo')->format('j M H:i') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </x-admin.card>
    <x-rq-pager :page="$offers" />
</div>
