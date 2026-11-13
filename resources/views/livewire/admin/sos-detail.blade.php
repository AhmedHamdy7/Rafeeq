{{-- SOS DETAIL (O02–O04). Polls while open: the desk must see a pick-up by somebody else at once. --}}
@php
    use App\Domains\Commute\Enums\CommuteLocationType;
    use Illuminate\Support\Facades\Lang;

    $event = $sos->safetyEvent;
    $member = $event?->user;
    $trip = $event?->tripSession;
    $offer = $trip?->scheduledTrip?->commuteOffer;
    $origin = $offer?->locations->firstWhere('type', CommuteLocationType::Origin);
    $destination = $offer?->locations->firstWhere('type', CommuteLocationType::Destination);
    $location = $sos->location_at_trigger;
    $untouched = $open && $sos->first_touch_at === null;
@endphp
<div @if ($open) wire:poll.10s.visible @endif>
    <nav class="ad-muted" style="font-size:12.5px;margin-block-end:6px">
        <a class="ad-link" href="{{ route('admin.safety') }}" wire:navigate>{{ __('admin/shell.nav.sos') }}</a>
        <x-admin.icon name="chevron" size="12" class="ad-flip" />
        <span class="ad-code">SOS-{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($sos->id, -6)) }}</span>
    </nav>

    <x-admin.page-head :title="__('admin/safety.detail_title', ['name' => (string) $member?->full_name])">
        @if ($open && $mayAct)
            @if ($untouched)
                <button type="button" class="ad-btn ad-btn--danger" wire:click="acknowledge('{{ $sos->id }}')" wire:loading.attr="disabled"><x-admin.icon name="check" />{{ __('admin.safety.acknowledge') }}</button>
            @else
                <button type="button" class="ad-btn ad-btn--primary" wire:click="$set('openAlert', '{{ $sos->id }}')">{{ __('admin.safety.record_outcome') }}</button>
            @endif
        @endif
    </x-admin.page-head>

    @if (session('status'))
        <p class="ad-flash" role="status"><x-admin.icon name="check" />{{ session('status') }}</p>
    @endif
    @error('alerts') <p class="ad-flash ad-flash--error" role="alert"><x-admin.icon name="alert" />{{ $message }}</p> @enderror

    <div class="ad-notice" style="margin-block-end:12px"><x-admin.icon name="info" /><span>{{ __('admin/safety.never_end_ride') }}</span></div>

    @if ($openAlert === $sos->id && $open && ! $untouched && $mayAct)
        <x-admin.card :title="__('admin.safety.record_outcome')" bar style="margin-block-end:12px">
            <form wire:submit="resolveAlert('{{ $sos->id }}')">
                <div class="ad-field">
                    <label class="ad-label" for="outcome">{{ __('admin.safety.outcome') }}</label>
                    <select id="outcome" class="ad-select" wire:model="resolution" required>
                        <option value="">—</option>
                        @foreach ($outcomes as $outcome)
                            <option value="{{ $outcome->value }}">{{ __('admin.safety.outcomes.'.$outcome->value) }}</option>
                        @endforeach
                    </select>
                    @error('resolution') <span class="ad-error">{{ $message }}</span> @enderror
                </div>
                <div class="ad-field">
                    <textarea class="ad-textarea" wire:model="note" rows="3" required aria-label="{{ __('admin.safety.note_placeholder') }}" placeholder="{{ __('admin.safety.note_placeholder') }}"></textarea>
                    @error('note') <span class="ad-error">{{ $message }}</span> @enderror
                </div>
                <div class="ad-row">
                    <button type="submit" class="ad-btn ad-btn--primary" wire:loading.attr="disabled">{{ __('admin.safety.close_alert') }}</button>
                    <button type="button" class="ad-btn" wire:click="$set('openAlert', null)">{{ __('admin.queue.cancel') }}</button>
                </div>
            </form>
        </x-admin.card>
    @endif

    <div class="ad-split">
        <div class="ad-stack">
            <x-admin.card :title="__('admin/safety.who')" bar :tone="$untouched ? 'alert' : null">
                <div class="ad-row" style="gap:12px;flex-wrap:nowrap">
                    <span class="ad-avatar" style="inline-size:48px;block-size:48px;font-size:18px" aria-hidden="true">{{ mb_substr($member?->full_name ?? '?', 0, 1) }}</span>
                    <div style="flex:1;min-inline-size:0">
                        <div style="font-weight:700">
                            @can('member.view')
                                <a class="ad-link" href="{{ route('admin.members.show', $member?->id ?? '-') }}" wire:navigate><bdi>{{ $member?->full_name ?? '—' }}</bdi></a>
                            @else
                                <bdi>{{ $member?->full_name ?? '—' }}</bdi>
                            @endcan
                        </div>
                        <div class="ad-muted" style="font-size:12.5px">{{ __('admin.safety.raised', ['ago' => $sos->created_at->diffForHumans(), 'time' => $sos->created_at->timezone('Africa/Cairo')->format('H:i:s')]) }}</div>
                    </div>
                    @if (! $open)
                        <span class="ad-chip">{{ $sos->resolution ? __('admin.safety.outcomes.'.$sos->resolution->value) : __('admin/safety.cancelled_by_member') }}</span>
                    @elseif ($untouched)
                        <span class="ad-chip ad-chip--bad">{{ __('admin.safety.nobody_on_it') }}</span>
                    @else
                        <span class="ad-chip ad-chip--warn">{{ __('admin.safety.picked_up_by', ['name' => $sos->responderAdmin?->name ?? '—', 'seconds' => $sos->responseSeconds()]) }}</span>
                    @endif
                    @if ($sos->is_discreet)<span class="ad-chip ad-chip--bad">{{ __('admin.safety.silent') }}</span>@endif
                </div>
                @if ($sos->is_discreet && $open)
                    <p class="ad-flash ad-flash--error" style="margin:12px 0 0"><x-admin.icon name="alert" />{{ __('admin.safety.silent_hint') }}</p>
                @endif
                <dl class="ad-kv" style="margin-block-start:14px">
                    <dt>{{ __('admin.safety.where') }}</dt>
                    <dd>
                        @if ($location !== null)
                            <span class="ad-mono" dir="ltr">{{ number_format($location->lat, 5) }}, {{ number_format($location->lng, 5) }}</span>
                            · <a class="ad-link" href="https://www.google.com/maps?q={{ $location->lat }},{{ $location->lng }}" target="_blank" rel="noopener noreferrer">{{ __('admin/safety.open_map') }}</a>
                        @else
                            {{ __('admin.safety.no_location') }}
                        @endif
                    </dd>
                </dl>
            </x-admin.card>

            <x-admin.card :title="__('admin/safety.the_run')" bar>
                @if ($trip === null)
                    <p class="ad-hint" style="margin:0">{{ __('admin.safety.no_trip') }}</p>
                @else
                    <dl class="ad-facts" style="margin:0">
                        <div><dt>{{ __('admin/directory.route') }}</dt><dd><bdi>{{ $origin?->address ?? '—' }}</bdi> → <bdi>{{ $destination?->address ?? '—' }}</bdi></dd></div>
                        <div><dt>{{ __('admin/directory.status') }}</dt><dd>{{ __('admin.trips.status.'.$trip->current_status->value) }}</dd></div>
                        <div><dt>{{ __('admin/directory.driver') }}</dt><dd><bdi>{{ $offer?->driverProfile?->user?->full_name ?? '—' }}</bdi></dd></div>
                        <div><dt>{{ __('admin/directory.car') }}</dt><dd><bdi>{{ $offer?->vehicle?->make }} {{ $offer?->vehicle?->model }}, {{ $offer?->vehicle?->colour }}</bdi> · <span class="ad-mono"><bdi>{{ $offer?->vehicle?->plate_number }}</bdi></span></dd></div>
                    </dl>
                @endif
            </x-admin.card>
        </div>

        <div class="ad-stack">
            <x-admin.card :title="__('admin/safety.emergency_numbers')" bar>
                <div class="ad-grid ad-grid--3 ad-emergency">
                    @foreach (['122' => 'police', '123' => 'ambulance', '180' => 'fire'] as $number => $service)
                        <a class="ad-emergency__item" href="tel:{{ $number }}">
                            <span class="ad-emergency__number ad-num">{{ $number }}</span>
                            <span class="ad-muted">{{ __('admin/safety.services.'.$service) }}</span>
                        </a>
                    @endforeach
                </div>
            </x-admin.card>

            <x-admin.card :title="__('admin/safety.timeline')" bar>
                <ol class="ad-timeline">
                    @foreach ($timeline as $item)
                        @php($label = 'admin.audit.actions.'.str_replace('.', '__', $item['key']))
                        <li @class(['ad-timeline__item', 'ad-timeline__item--bad' => $item['key'] === 'raised'])>
                            <span class="ad-timeline__time ad-num">{{ $item['at']?->timezone('Africa/Cairo')->format('H:i') }}</span>
                            <span class="ad-timeline__body">
                                <strong>{{ Lang::has('admin/safety.events.'.$item['key']) ? __('admin/safety.events.'.$item['key']) : (Lang::has($label) ? __($label) : $item['key']) }}</strong>
                                @if ($item['who'])<span class="ad-muted" style="font-size:12px"> · <bdi>{{ $item['who'] }}</bdi></span>@endif
                                @if ($item['note'])<span class="ad-muted" style="display:block;font-size:12.5px" dir="auto">{{ $item['note'] }}</span>@endif
                            </span>
                        </li>
                    @endforeach
                    @if ($untouched)
                        <li class="ad-timeline__item ad-timeline__item--waiting">
                            <span class="ad-timeline__time">—</span>
                            <span class="ad-timeline__body"><strong>{{ __('admin/safety.events.waiting') }}</strong></span>
                        </li>
                    @endif
                </ol>
            </x-admin.card>
        </div>
    </div>
</div>
