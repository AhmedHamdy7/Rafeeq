{{-- TRIP DAY DETAIL (Y02, Y03). Read-only; the chat opens only inside a report (Y04). --}}
@php
    use App\Domains\Commute\Enums\CommuteLocationType;
    use App\Livewire\Admin\Directory\DirectoryPage;

    $offer = $trip->commuteOffer;
    $driver = $offer?->driverProfile?->user;
    $egp = fn ($p) => __('admin/home.egp', ['amount' => number_format(((int) $p) / 100, 2)]);
@endphp
<div>
    <nav class="ad-muted" style="font-size:12.5px;margin-block-end:6px">
        <a class="ad-link" href="{{ route('admin.trip-days') }}" wire:navigate>{{ __('admin/directory.trip_days.title') }}</a>
        <x-admin.icon name="chevron" size="12" class="ad-flip" />
        <span class="ad-num">{{ $trip->trip_date?->format('D j M Y') }}</span>
    </nav>

    <x-admin.page-head
        :title="($offer?->locations->firstWhere('type', CommuteLocationType::Origin)?->address ?? '—').' → '.($offer?->locations->firstWhere('type', CommuteLocationType::Destination)?->address ?? '—')"
        :sub="$trip->trip_date?->format('D j M Y').' · '.$trip->departure_at?->timezone('Africa/Cairo')->format('H:i')"
    />

    <div class="ad-split">
        <div class="ad-stack">
            <x-admin.card :title="__('admin/records.trip.run')" bar>
                <dl class="ad-facts" style="margin:0">
                    <div><dt>{{ __('admin/directory.status') }}</dt><dd><span class="ad-chip ad-chip--{{ DirectoryPage::tone($trip->status->value) }}">{{ __('admin/directory.statuses.'.$trip->status->value) }}</span>@if ($trip->cancelled_reason) <span class="ad-muted">· <bdi>{{ $trip->cancelled_reason }}</bdi></span>@endif</dd></div>
                    <div><dt>{{ __('admin/directory.driver') }}</dt><dd>@if ($driver)<a class="ad-link" href="{{ route('admin.members.show', $driver->id) }}" wire:navigate><bdi>{{ $driver->full_name }}</bdi></a>@else — @endif</dd></div>
                    <div><dt>{{ __('admin/records.trip.commute') }}</dt><dd>@if ($offer)<a class="ad-link" href="{{ route('admin.commutes.show', $offer->id) }}" wire:navigate>{{ __('admin/records.open') }}</a>@else — @endif</dd></div>
                    <div><dt>{{ __('admin/directory.seats') }}</dt><dd class="ad-num">{{ $trip->seats_taken }} / {{ $trip->seats_total }}</dd></div>
                </dl>
                <ol class="ad-timeline" style="margin-block-start:14px">
                    @foreach ($timeline as $step)
                        <li @class(['ad-timeline__item', 'ad-timeline__item--bad' => in_array($step['key'], ['deviation', 'cancelled'], true)])>
                            <span class="ad-timeline__time ad-num">{{ $step['at']->timezone('Africa/Cairo')->format('H:i') }}</span>
                            <span class="ad-timeline__body"><strong>{{ __('admin/records.trip.steps.'.$step['key']) }}</strong></span>
                        </li>
                    @endforeach
                </ol>
            </x-admin.card>

            <div class="ad-grid ad-grid--2">
                <x-admin.card flush><x-admin.stat :label="__('admin/records.trip.seats')" :value="$money['seats']" /></x-admin.card>
                <x-admin.card flush><x-admin.stat money :label="__('admin/records.trip.gross')" :value="$egp($money['gross'])" /></x-admin.card>
                <x-admin.card flush><x-admin.stat money :label="__('admin/records.trip.commission')" :value="$egp($money['commission'])" /></x-admin.card>
                <x-admin.card flush><x-admin.stat money :label="__('admin/records.trip.driver')" :value="$egp($money['driver'])" /></x-admin.card>
            </div>
        </div>

        <div class="ad-stack">
            <x-admin.card flush :title="__('admin/records.trip.passengers')" bar>
                @if ($bookings->isEmpty())
                    <x-admin.state kind="empty" :title="__('admin/records.none')" />
                @else
                    <div class="ad-table-wrap"><table class="ad-table ad-table--cards">
                        <thead><tr><th>{{ __('admin/records.trip.passenger') }}</th><th>{{ __('admin/directory.status') }}</th><th>{{ __('admin/records.trip.attendance') }}</th><th class="ad-end">{{ __('admin/records.trip.price') }}</th></tr></thead>
                        <tbody>
                        @foreach ($bookings as $booking)
                            <tr wire:key="tb-{{ $booking->id }}">
                                <td data-label="{{ __('admin/records.trip.passenger') }}"><a class="ad-link" href="{{ route('admin.bookings.show', $booking->id) }}" wire:navigate><bdi>{{ $booking->passenger?->full_name ?? '—' }}</bdi></a></td>
                                <td data-label="{{ __('admin/directory.status') }}"><span class="ad-chip ad-chip--{{ DirectoryPage::tone($booking->status->value) }}">{{ __('admin/directory.statuses.'.$booking->status->value) }}</span></td>
                                <td data-label="{{ __('admin/records.trip.attendance') }}">{{ $booking->attendance ? __('admin/records.attendance.'.$booking->attendance->status->value) : '—' }}</td>
                                <td data-label="{{ __('admin/records.trip.price') }}" class="ad-end ad-num">{{ $egp($booking->price_snapshot_piastres) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @endif
            </x-admin.card>
        </div>
    </div>
</div>
