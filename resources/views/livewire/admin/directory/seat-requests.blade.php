{{-- SEAT REQUESTS (B02). Read-only. --}}
@php use App\Domains\Commute\Enums\CommuteLocationType; use App\Livewire\Admin\Directory\DirectoryPage; @endphp
<div>
    <x-admin.page-head :title="__('admin/directory.bookings.title')" :sub="trans_choice('admin/directory.count', $requests->total(), ['count' => $requests->total()])" />
    @include('livewire.admin.directory._bookings-nav')
    @include('livewire.admin.directory._tabs')
    <x-admin.card flush>
        @if ($requests->isEmpty())
            <x-admin.state kind="empty" :title="__('admin/directory.empty')" />
        @else
            <div class="ad-table-wrap"><table class="ad-table ad-table--cards">
                <thead><tr>
                    <th>{{ __('admin/directory.passenger') }}</th>
                    <th>{{ __('admin/directory.route') }}</th>
                    <th>{{ __('admin/directory.driver') }}</th>
                    <th>{{ __('admin/directory.commitment') }}</th>
                    <th class="ad-end">{{ __('admin/directory.seats') }}</th>
                    <th>{{ __('admin/directory.status') }}</th>
                    <th>{{ __('admin/directory.created') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($requests as $request)
                    @php($offer = $request->commuteOffer)
                    <tr wire:key="sr-{{ $request->id }}">
                        <td data-label="{{ __('admin/directory.passenger') }}"><bdi>{{ $request->passenger?->full_name ?? '—' }}</bdi></td>
                        <td data-label="{{ __('admin/directory.route') }}">@if ($offer)<a class="ad-link" href="{{ route('admin.commutes.show', $offer->id) }}" wire:navigate><bdi>{{ $offer->locations->firstWhere('type', CommuteLocationType::Origin)?->address ?? '—' }}</bdi> → <bdi>{{ $offer->locations->firstWhere('type', CommuteLocationType::Destination)?->address ?? '—' }}</bdi></a>@else — @endif</td>
                        <td data-label="{{ __('admin/directory.driver') }}"><bdi>{{ $offer?->driverProfile?->user?->full_name ?? '—' }}</bdi></td>
                        <td data-label="{{ __('admin/directory.commitment') }}">{{ $request->commitment ? __('admin/directory.commitments.'.$request->commitment->value) : '—' }}</td>
                        <td data-label="{{ __('admin/directory.seats') }}" class="ad-end ad-num">{{ $request->seats }}</td>
                        <td data-label="{{ __('admin/directory.status') }}"><span class="ad-chip ad-chip--{{ DirectoryPage::tone($request->status->value) }}">{{ \Illuminate\Support\Facades\Lang::has('admin/directory.statuses.'.$request->status->value) ? __('admin/directory.statuses.'.$request->status->value) : $request->status->value }}</span></td>
                        <td data-label="{{ __('admin/directory.created') }}" class="ad-muted ad-num">{{ $request->created_at?->timezone('Africa/Cairo')->format('j M Y') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </x-admin.card>
    <x-rq-pager :page="$requests" />
</div>
