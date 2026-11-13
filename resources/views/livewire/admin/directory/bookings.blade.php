{{-- BOOKINGS (B). Every seat booked, newest first. Read-only. --}}
@php use App\Livewire\Admin\Directory\DirectoryPage; @endphp
<div>
    <x-admin.page-head :title="__('admin/directory.bookings.title')" :sub="trans_choice('admin/directory.count', $bookings->total(), ['count' => $bookings->total()])" />
    @include('livewire.admin.directory._tabs')
    <x-admin.card flush>
        @if ($bookings->isEmpty())
            <x-admin.state kind="empty" :title="__('admin/directory.empty')" />
        @else
            <div class="ad-table-wrap">
                <table class="ad-table ad-table--cards">
                    <thead><tr>
                        <th>{{ __('admin/directory.ref') }}</th>
                        <th>{{ __('admin/directory.passenger') }}</th>
                        <th>{{ __('admin/directory.driver') }}</th>
                        <th>{{ __('admin/directory.day') }}</th>
                        <th class="ad-end">{{ __('admin/directory.seats') }}</th>
                        <th class="ad-end">{{ __('admin/directory.price') }}</th>
                        <th>{{ __('admin/directory.status') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($bookings as $booking)
                        <tr wire:key="booking-{{ $booking->id }}">
                            <td data-label="{{ __('admin/directory.ref') }}"><a class="ad-link ad-code" href="{{ route('admin.bookings.show', $booking->id) }}" wire:navigate>{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($booking->id, -6)) }}</a></td>
                            <td data-label="{{ __('admin/directory.passenger') }}"><bdi>{{ $booking->passenger?->full_name ?? '—' }}</bdi></td>
                            <td data-label="{{ __('admin/directory.driver') }}"><bdi>{{ $booking->driverProfile?->user?->full_name ?? '—' }}</bdi></td>
                            <td data-label="{{ __('admin/directory.day') }}" class="ad-num">{{ $booking->scheduledTrip?->trip_date?->format('D j M') }} · {{ $booking->scheduledTrip?->departure_at?->timezone('Africa/Cairo')->format('H:i') }}</td>
                            <td data-label="{{ __('admin/directory.seats') }}" class="ad-end ad-num">{{ $booking->seats_reserved }}</td>
                            <td data-label="{{ __('admin/directory.price') }}" class="ad-end ad-num">EGP {{ number_format(($booking->price_snapshot_piastres ?? 0) / 100, 2) }}</td>
                            <td data-label="{{ __('admin/directory.status') }}"><span class="ad-chip ad-chip--{{ DirectoryPage::tone($booking->status->value) }}">{{ __('admin/directory.statuses.'.$booking->status->value) }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-admin.card>
    <x-rq-pager :page="$bookings" />
</div>
