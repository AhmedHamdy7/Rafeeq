{{--
    SOS ALERTS AND SAFETY CASES (O01–O04, PH2). Polls every ten seconds while the tab is
    visible — see the component for why this is the one page that must not be a snapshot.
    `.visible` so a dashboard left open in a background tab overnight is not querying the
    queue 8,640 times for nobody.

    Live alerts first (nobody on it → red), then the open reports. Reports get their own
    page in phase 5; until then they keep every action they had here.
--}}
<div wire:poll.10s.visible>
    <x-admin.page-head
        :title="__('admin.safety.title')"
        :sub="trans_choice('admin.safety.alerts_live', $alerts->count(), ['count' => $alerts->count()]).' · '.trans_choice('admin.safety.reports_open', $reports->total(), ['count' => $reports->total()])"
    />

    {{-- SAFE-12: an SOS never ends the ride, and nothing on this page does either. --}}
    <div class="ad-notice" style="margin-block-end:16px">
        <x-admin.icon name="info" />
        <span>{{ __('admin/safety.never_end_ride') }}</span>
    </div>

    <div class="ad-split">
        {{-- ─────────── Live alerts ─────────── --}}
        <section class="ad-stack" aria-labelledby="alerts-heading">
            <div class="ad-section-label" id="alerts-heading" style="margin-block-start:0">{{ __('admin.safety.alerts') }}</div>

            @error('alerts') <p class="ad-flash ad-flash--error" role="alert"><x-admin.icon name="alert" />{{ $message }}</p> @enderror

            @forelse ($alerts as $alert)
                @php
                    $event = $alert->safetyEvent;
                    $member = $event->user;
                    $trip = $event->tripSession;
                    $offer = $trip?->scheduledTrip?->commuteOffer;
                    $location = $alert->location_at_trigger;
                    $untouched = $alert->first_touch_at === null;
                @endphp
                <x-admin.card flush :tone="$untouched ? 'alert' : null" wire:key="alert-{{ $alert->id }}">
                    <div class="ad-card__body">
                        <div class="ad-row" style="gap:12px">
                            <span class="ad-avatar" aria-hidden="true">{{ mb_substr($member->full_name ?? '?', 0, 1) }}</span>
                            <div style="flex:1;min-inline-size:160px">
                                <div style="font-weight:700"><bdi>{{ $member->full_name }}</bdi></div>
                                <div class="ad-muted" style="font-size:12.5px">
                                    {{ __('admin.safety.raised', ['ago' => $alert->created_at->diffForHumans(), 'time' => $alert->created_at->timezone('Africa/Cairo')->format('H:i:s')]) }}
                                </div>
                            </div>
                            @if ($alert->is_discreet)
                                <span class="ad-chip ad-chip--bad">{{ __('admin.safety.silent') }}</span>
                            @endif
                            @if ($untouched)
                                <span class="ad-chip ad-chip--bad">{{ __('admin.safety.nobody_on_it') }}</span>
                            @else
                                <span class="ad-chip ad-chip--warn">
                                    {{ __('admin.safety.picked_up_by', ['name' => $alert->responderAdmin?->name ?? '—', 'seconds' => $alert->responseSeconds()]) }}
                                </span>
                            @endif
                        </div>

                        @if ($alert->is_discreet)
                            {{-- The one instruction that changes what the operator does next. --}}
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
                            <dt>{{ __('admin.safety.trip') }}</dt>
                            <dd>
                                @if ($trip !== null)
                                    <span class="ad-code">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($trip->id, -6)) }}</span>
                                    · {{ __('admin.trips.status.'.$trip->current_status->value) }}
                                @else
                                    {{ __('admin.safety.no_trip') }}
                                @endif
                            </dd>
                            @if ($offer !== null)
                                <dt>{{ __('admin.safety.driver_and_car') }}</dt>
                                <dd>
                                    {{-- `<bdi>` around what members typed, so Arabic does not reorder the English around it. --}}
                                    <bdi>{{ $offer->driverProfile?->user?->full_name }}</bdi>
                                    · <bdi>{{ $offer->vehicle?->make }} {{ $offer->vehicle?->model }}, {{ $offer->vehicle?->colour }}</bdi>
                                    · <span class="ad-mono"><bdi>{{ $offer->vehicle?->plate_number }}</bdi></span>
                                </dd>
                            @endif
                        </dl>

                        @if ($mayAct)
                            <div class="ad-row" style="margin-block-start:14px">
                                @if ($untouched)
                                    <button type="button" class="ad-btn ad-btn--danger"
                                            wire:click="acknowledge('{{ $alert->id }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="acknowledge('{{ $alert->id }}')">
                                        <x-admin.icon name="check" />{{ __('admin.safety.acknowledge') }}
                                    </button>
                                @else
                                    <button type="button" class="ad-btn" wire:click="$set('openAlert', '{{ $alert->id }}')">
                                        {{ __('admin.safety.record_outcome') }}
                                    </button>
                                @endif
                            </div>

                            @if ($openAlert === $alert->id && ! $untouched)
                                <form wire:submit="resolveAlert('{{ $alert->id }}')" style="margin-block-start:14px">
                                    <div class="ad-field">
                                        <label class="ad-label" for="outcome-{{ $alert->id }}">{{ __('admin.safety.outcome') }}</label>
                                        <select id="outcome-{{ $alert->id }}" class="ad-select" wire:model="resolution" required>
                                            <option value="">—</option>
                                            @foreach ($outcomes as $outcome)
                                                <option value="{{ $outcome->value }}">{{ __('admin.safety.outcomes.'.$outcome->value) }}</option>
                                            @endforeach
                                        </select>
                                        @error('resolution') <span class="ad-error">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="ad-field">
                                        <textarea class="ad-textarea" wire:model="note" rows="3" required
                                                  aria-label="{{ __('admin.safety.note_placeholder') }}"
                                                  placeholder="{{ __('admin.safety.note_placeholder') }}"></textarea>
                                        @error('note') <span class="ad-error">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="ad-row">
                                        <button type="submit" class="ad-btn ad-btn--primary" wire:loading.attr="disabled">{{ __('admin.safety.close_alert') }}</button>
                                        <button type="button" class="ad-btn" wire:click="$set('openAlert', null)">{{ __('admin.queue.cancel') }}</button>
                                    </div>
                                </form>
                            @endif
                        @else
                            <p class="ad-hint" style="margin:14px 0 0">{{ __('admin.queue.view_only') }}</p>
                        @endif
                    </div>
                </x-admin.card>
            @empty
                <x-admin.card flush>
                    <x-admin.state kind="empty" :title="__('admin.safety.no_alerts')" />
                </x-admin.card>
            @endforelse
        </section>

        {{-- Reference numbers, always on screen while handling an alert (O02). --}}
        <aside class="ad-stack">
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
        </aside>
    </div>

    {{-- ─────────── Open reports (also on their own page, Reports) ─────────── --}}
    <div class="ad-section-label">{{ __('admin.safety.reports') }}</div>
    @include('livewire.admin.partials.reports-list')
</div>
