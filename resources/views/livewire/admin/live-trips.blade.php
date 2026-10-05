{{--
    LIVE TRIPS. Polls every fifteen seconds while visible: "refreshed 4s ago" in the
    prototype is the promise that what is on screen is what is on the road.
--}}
@php
    use App\Domains\Admin\Support\LiveTripBoard;
    use App\Domains\Commute\Enums\CommuteLocationType;
    use Illuminate\Support\Str;
@endphp
<div wire:poll.15s.visible>
    <h1 class="rq-h1">{{ __('admin.trips.title') }}</h1>
    <p class="rq-sub">
        {{ trans_choice('admin.trips.in_progress', $trips->total(), ['count' => $trips->total()]) }}
        &middot; {{ __('admin.trips.refreshed', ['time' => now()->timezone('Africa/Cairo')->format('H:i:s')]) }}
    </p>

    <div class="rq-filters">
        <label><input type="checkbox" wire:model.live="flaggedOnly"> {{ __('admin.trips.flagged_only') }}</label>
        <label><input type="checkbox" wire:model.live="womenOnly"> {{ __('admin.trips.women_only') }}</label>
    </div>

    @forelse ($trips as $session)
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
        <article class="rq-card {{ $flags !== [] ? 'rq-card--alert' : '' }}" wire:key="trip-{{ $session->id }}">
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <span class="rq-mono">{{ Str::upper(Str::substr($session->id, -6)) }}</span>
                <div style="flex:1;min-width:200px">
                    {{-- `<bdi>` around everything a member typed: an Arabic address inside an English line is otherwise reordered by the browser into nonsense. --}}
                    <div style="font-weight:600"><bdi>{{ $origin?->address ?? '—' }}</bdi> → <bdi>{{ $destination?->address ?? '—' }}</bdi></div>
                    <div class="rq-note">
                        {{ __('admin.trips.departs', ['time' => $trip->departure_at->timezone('Africa/Cairo')->format('H:i')]) }}
                        &middot; <bdi>{{ $offer->driverProfile?->user?->public_first_name ?? '—' }}</bdi>
                        &middot; <bdi>{{ $offer->vehicle?->make }} {{ $offer->vehicle?->model }}, {{ $offer->vehicle?->colour }}</bdi>
                        &middot; {{ __('admin.trips.seats', ['taken' => $trip->seats_taken, 'total' => $trip->seats_total]) }}
                        @if ($offer->audience->value === 'women_only')
                            &middot; {{ __('admin.trips.women_only') }}
                        @endif
                    </div>
                </div>
                <span class="rq-pill rq-pill--count">{{ __('admin.trips.status.'.$session->current_status->value) }}</span>
                @foreach (array_keys($flags) as $flag)
                    <span class="rq-pill rq-pill--bad">
                        @if ($flag === 'deviation')
                            {{ __('admin.trips.flags.deviation', ['meters' => number_format((int) $session->deviation_distance_meters)]) }}
                        @else
                            {{ __('admin.trips.flags.'.$flag) }}
                        @endif
                    </span>
                @endforeach
            </div>

            <div class="rq-actions">
                <button type="button" class="rq-btn rq-btn--quiet" wire:click="track('{{ $session->id }}')">
                    {{ $trackId === $session->id ? __('admin.trips.hide') : __('admin.trips.track') }}
                </button>
                @if (isset($flags['alert']) && auth('admin')->user()?->can('safety.view'))
                    <a class="rq-btn rq-btn--danger" href="{{ route('admin.safety') }}" wire:navigate>{{ __('admin.trips.open_case') }}</a>
                @endif
            </div>

            @if ($tracked?->id === $session->id)
                <dl class="rq-facts">
                    <div>
                        <dt>{{ __('admin.trips.last_position') }}</dt>
                        <dd>
                            @if ($position !== null)
                                <span class="rq-mono">{{ number_format($position->lat, 5) }}, {{ number_format($position->lng, 5) }}</span>
                                ({{ $position->recordedAt->diffForHumans() }})
                            @else
                                {{ __('admin.trips.no_position') }}
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt>{{ __('admin.trips.plate') }}</dt>
                        <dd class="rq-mono"><bdi>{{ $offer->vehicle?->plate_number ?? '—' }}</bdi></dd>
                    </div>
                    <div>
                        <dt>{{ __('admin.trips.departed') }}</dt>
                        <dd>{{ $session->departed_at?->timezone('Africa/Cairo')->format('H:i') ?? __('admin.trips.not_yet') }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('admin.trips.riders') }}</dt>
                        <dd><bdi>{{ $riders->isEmpty() ? '—' : $riders->implode(', ') }}</bdi></dd>
                    </div>
                </dl>
            @endif
        </article>
    @empty
        <p class="rq-empty">{{ $flaggedOnly ? __('admin.trips.nothing_flagged') : __('admin.trips.empty') }}</p>
    @endforelse

    <x-rq-pager :page="$trips" position="admin.pager.position_trips" />
</div>
