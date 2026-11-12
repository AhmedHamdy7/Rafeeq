{{--
    LIVE TRIPS (L01–L03). Polls every fifteen seconds while visible: "refreshed 4s ago" in
    the design is the promise that what is on screen is what is on the road.

    A table (cards on a phone); a flagged run is red down its start edge. "Track" opens the
    run's last position, plate and riders under its row. The map in the design comes with a
    map provider (a decision still open in the plan), not before.
--}}
@php
    use App\Domains\Admin\Support\LiveTripBoard;
    use App\Domains\Commute\Enums\CommuteLocationType;
    use Illuminate\Support\Str;
@endphp
<div wire:poll.15s.visible>
    <x-admin.page-head
        :title="__('admin.trips.title')"
        :sub="trans_choice('admin.trips.in_progress', $trips->total(), ['count' => $trips->total()]).' · '.__('admin.trips.refreshed', ['time' => now()->timezone('Africa/Cairo')->format('H:i:s')])"
    />

    <div class="ad-toolbar">
        <button type="button" class="ad-fchip" wire:click="$toggle('flaggedOnly')" aria-pressed="{{ $flaggedOnly ? 'true' : 'false' }}">
            <x-admin.icon name="flag" size="14" />{{ __('admin.trips.flagged_only') }}
        </button>
        <button type="button" class="ad-fchip" wire:click="$toggle('womenOnly')" aria-pressed="{{ $womenOnly ? 'true' : 'false' }}">
            {{ __('admin.trips.women_only') }}
        </button>
    </div>

    <x-admin.card flush>
        @if ($trips->isEmpty())
            <x-admin.state kind="empty" :title="$flaggedOnly ? __('admin.trips.nothing_flagged') : __('admin.trips.empty')" />
        @else
            <div class="ad-table-wrap">
                <table class="ad-table ad-table--cards">
                    <thead>
                    <tr>
                        <th>{{ __('admin/trips.ref') }}</th>
                        <th>{{ __('admin/trips.route') }}</th>
                        <th>{{ __('admin/trips.driver_car') }}</th>
                        <th>{{ __('admin/trips.state') }}</th>
                        <th class="ad-end">{{ __('admin/trips.seats') }}</th>
                        <th><span class="ad-sr">{{ __('admin/trips.actions') }}</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($trips as $session)
                        @php
                            $trip = $session->scheduledTrip;
                            $offer = $trip->commuteOffer;
                            $origin = $offer->locations->firstWhere('type', CommuteLocationType::Origin);
                            $destination = $offer->locations->firstWhere('type', CommuteLocationType::Destination);
                            $silent = LiveTripBoard::isSilent($session);
                            $flags = array_filter([
                                'alert' => (bool) $session->has_live_alert,
                                'emergency' => $session->current_status->value === 'emergency',
                                'deviation' => $session->deviation_detected_at !== null,
                                'silent' => $silent,
                            ]);
                        @endphp
                        <tr wire:key="trip-{{ $session->id }}" @class(['ad-row--flagged' => $flags !== []]) @if ($trackId === $session->id) aria-selected="true" @endif>
                            <td data-label="{{ __('admin/trips.ref') }}"><span class="ad-code">{{ Str::upper(Str::substr($session->id, -6)) }}</span></td>
                            <td data-label="{{ __('admin/trips.route') }}">
                                {{-- `<bdi>` around everything a member typed: an Arabic address inside an English line is otherwise reordered by the browser into nonsense. --}}
                                <div style="font-weight:650"><bdi>{{ $origin?->address ?? '—' }}</bdi> <x-admin.icon name="chevron" size="12" class="ad-flip ad-subtle" /> <bdi>{{ $destination?->address ?? '—' }}</bdi></div>
                                <div class="ad-muted" style="font-size:12px">{{ __('admin.trips.departs', ['time' => $trip->departure_at->timezone('Africa/Cairo')->format('H:i')]) }}</div>
                            </td>
                            <td data-label="{{ __('admin/trips.driver_car') }}">
                                <bdi>{{ $offer->driverProfile?->user?->public_first_name ?? '—' }}</bdi>
                                <div class="ad-muted" style="font-size:12px"><bdi>{{ $offer->vehicle?->make }} {{ $offer->vehicle?->model }}, {{ $offer->vehicle?->colour }}</bdi></div>
                            </td>
                            <td data-label="{{ __('admin/trips.state') }}">
                                <div class="ad-row" style="gap:4px">
                                    <span class="ad-chip ad-chip--info">{{ __('admin.trips.status.'.$session->current_status->value) }}</span>
                                    @if ($offer->audience->value === 'women_only')
                                        <span class="ad-chip ad-chip--plain">{{ __('admin.trips.women_only') }}</span>
                                    @endif
                                    {{-- Not a problem flag: it says the desk is watching this corridor, not that anything is wrong. --}}
                                    @if ($offer->corridor_id !== null && in_array($offer->corridor_id, $escortCorridors, true))
                                        <span class="ad-chip ad-chip--warn">{{ __('admin.trips.escort') }}</span>
                                    @endif
                                    @foreach (array_keys($flags) as $flag)
                                        <span class="ad-chip ad-chip--bad">
                                            @if ($flag === 'deviation')
                                                {{ __('admin.trips.flags.deviation', ['meters' => number_format((int) $session->deviation_distance_meters)]) }}
                                            @else
                                                {{ __('admin.trips.flags.'.$flag) }}
                                            @endif
                                        </span>
                                    @endforeach
                                </div>
                            </td>
                            <td data-label="{{ __('admin/trips.seats') }}" class="ad-end ad-num">{{ __('admin.trips.seats', ['taken' => $trip->seats_taken, 'total' => $trip->seats_total]) }}</td>
                            <td class="ad-end">
                                <div class="ad-row" style="justify-content:flex-end;gap:6px;flex-wrap:nowrap">
                                    @if (isset($flags['alert']) && auth('admin')->user()?->can('safety.view'))
                                        <a class="ad-btn ad-btn--danger ad-btn--sm" href="{{ route('admin.safety') }}" wire:navigate>{{ __('admin.trips.open_case') }}</a>
                                    @endif
                                    <button type="button" class="ad-btn ad-btn--sm" wire:click="track('{{ $session->id }}')">
                                        {{ $trackId === $session->id ? __('admin.trips.hide') : __('admin.trips.track') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @if ($tracked?->id === $session->id)
                            <tr wire:key="trip-detail-{{ $session->id }}" class="ad-row-detail">
                                <td colspan="6">
                                    <dl class="ad-kv">
                                        <dt>{{ __('admin.trips.last_position') }}</dt>
                                        <dd>
                                            @if ($position !== null)
                                                <span class="ad-mono" dir="ltr">{{ number_format($position->lat, 5) }}, {{ number_format($position->lng, 5) }}</span>
                                                ({{ $position->recordedAt->diffForHumans() }})
                                            @else
                                                {{ __('admin.trips.no_position') }}
                                            @endif
                                        </dd>
                                        <dt>{{ __('admin.trips.plate') }}</dt>
                                        <dd class="ad-mono"><bdi>{{ $offer->vehicle?->plate_number ?? '—' }}</bdi></dd>
                                        <dt>{{ __('admin.trips.departed') }}</dt>
                                        <dd>{{ $session->departed_at?->timezone('Africa/Cairo')->format('H:i') ?? __('admin.trips.not_yet') }}</dd>
                                        <dt>{{ __('admin.trips.riders') }}</dt>
                                        <dd><bdi>{{ $riders->isEmpty() ? '—' : $riders->implode(', ') }}</bdi></dd>
                                    </dl>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-admin.card>

    <x-rq-pager :page="$trips" position="admin.pager.position_trips" />
</div>
