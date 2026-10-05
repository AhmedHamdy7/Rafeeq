{{--
    SAFETY CASES. Polls every ten seconds while the tab is visible — see the component for
    why this is the one page that must not be a snapshot. `.visible` so a dashboard left
    open in a background tab overnight is not querying the queue 8,640 times for nobody.
--}}
<div wire:poll.10s.visible>
    <h1 class="rq-h1">{{ __('admin.safety.title') }}</h1>
    <p class="rq-sub">
        {{ trans_choice('admin.safety.alerts_live', $alerts->count(), ['count' => $alerts->count()]) }}
        &middot;
        {{ trans_choice('admin.safety.reports_open', $reports->total(), ['count' => $reports->total()]) }}
    </p>

    {{-- ─────────── Live alerts ─────────── --}}
    <h2 class="rq-h2">{{ __('admin.safety.alerts') }}</h2>

    @error('alerts') <p class="rq-error" style="margin-bottom:14px">{{ $message }}</p> @enderror

    @forelse ($alerts as $alert)
        @php
            $event = $alert->safetyEvent;
            $member = $event->user;
            $trip = $event->tripSession;
            $offer = $trip?->scheduledTrip?->commuteOffer;
            $location = $alert->location_at_trigger;
        @endphp
        <article class="rq-card {{ $alert->first_touch_at === null ? 'rq-card--alert' : '' }}" wire:key="alert-{{ $alert->id }}">
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <span class="rq-avatar">{{ mb_substr($member->full_name ?? '?', 0, 1) }}</span>
                <div style="flex:1;min-width:180px">
                    <div style="font-weight:600"><bdi>{{ $member->full_name }}</bdi></div>
                    <div class="rq-note">
                        {{ __('admin.safety.raised', ['ago' => $alert->created_at->diffForHumans(), 'time' => $alert->created_at->timezone('Africa/Cairo')->format('H:i:s')]) }}
                    </div>
                </div>
                @if ($alert->is_discreet)
                    <span class="rq-pill rq-pill--bad">{{ __('admin.safety.silent') }}</span>
                @endif
                @if ($alert->first_touch_at === null)
                    <span class="rq-pill rq-pill--bad">{{ __('admin.safety.nobody_on_it') }}</span>
                @else
                    <span class="rq-pill rq-pill--warn">
                        {{ __('admin.safety.picked_up_by', ['name' => $alert->responderAdmin?->name ?? '—', 'seconds' => $alert->responseSeconds()]) }}
                    </span>
                @endif
            </div>

            @if ($alert->is_discreet)
                {{-- The one instruction that changes what the operator does next. --}}
                <p class="rq-error" style="margin-top:10px">{{ __('admin.safety.silent_hint') }}</p>
            @endif

            <dl class="rq-facts">
                <div>
                    <dt>{{ __('admin.safety.where') }}</dt>
                    <dd>
                        @if ($location !== null)
                            <span class="rq-mono">{{ number_format($location->lat, 5) }}, {{ number_format($location->lng, 5) }}</span>
                        @else
                            {{ __('admin.safety.no_location') }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt>{{ __('admin.safety.trip') }}</dt>
                    <dd>
                        @if ($trip !== null)
                            <span class="rq-mono">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($trip->id, -6)) }}</span>
                            &middot; {{ __('admin.trips.status.'.$trip->current_status->value) }}
                        @else
                            {{ __('admin.safety.no_trip') }}
                        @endif
                    </dd>
                </div>
                @if ($offer !== null)
                    <div>
                        <dt>{{ __('admin.safety.driver_and_car') }}</dt>
                        <dd>
                            {{-- `<bdi>` around what members typed, so Arabic does not reorder the English around it. --}}
                            <bdi>{{ $offer->driverProfile?->user?->full_name }}</bdi>
                            &middot; <bdi>{{ $offer->vehicle?->make }} {{ $offer->vehicle?->model }}, {{ $offer->vehicle?->colour }}</bdi>
                            &middot; <span class="rq-mono"><bdi>{{ $offer->vehicle?->plate_number }}</bdi></span>
                        </dd>
                    </div>
                @endif
            </dl>

            @if ($mayAct)
                <div class="rq-actions">
                    @if ($alert->first_touch_at === null)
                        <button type="button" class="rq-btn rq-btn--danger"
                                wire:click="acknowledge('{{ $alert->id }}')"
                                wire:loading.attr="disabled"
                                wire:target="acknowledge('{{ $alert->id }}')">
                            {{ __('admin.safety.acknowledge') }}
                        </button>
                    @else
                        <button type="button" class="rq-btn rq-btn--quiet" wire:click="$set('openAlert', '{{ $alert->id }}')">
                            {{ __('admin.safety.record_outcome') }}
                        </button>
                    @endif
                </div>

                @if ($openAlert === $alert->id && $alert->first_touch_at !== null)
                    <form wire:submit="resolveAlert('{{ $alert->id }}')" style="margin-top:13px">
                        <label class="rq-label" for="outcome-{{ $alert->id }}">{{ __('admin.safety.outcome') }}</label>
                        <select id="outcome-{{ $alert->id }}" class="rq-field" wire:model="resolution" required>
                            <option value="">—</option>
                            @foreach ($outcomes as $outcome)
                                <option value="{{ $outcome->value }}">{{ __('admin.safety.outcomes.'.$outcome->value) }}</option>
                            @endforeach
                        </select>
                        @error('resolution') <p class="rq-error">{{ $message }}</p> @enderror

                        <textarea class="rq-field" wire:model="note" rows="3" required style="margin-top:10px"
                                  placeholder="{{ __('admin.safety.note_placeholder') }}"></textarea>
                        @error('note') <p class="rq-error">{{ $message }}</p> @enderror

                        <div class="rq-actions">
                            <button type="submit" class="rq-btn rq-btn--primary" wire:loading.attr="disabled">
                                {{ __('admin.safety.close_alert') }}
                            </button>
                            <button type="button" class="rq-btn rq-btn--quiet" wire:click="$set('openAlert', null)">
                                {{ __('admin.queue.cancel') }}
                            </button>
                        </div>
                    </form>
                @endif
            @else
                <p class="rq-note" style="margin:14px 0 0">{{ __('admin.queue.view_only') }}</p>
            @endif
        </article>
    @empty
        <p class="rq-empty">{{ __('admin.safety.no_alerts') }}</p>
    @endforelse

    {{-- ─────────── Open reports ─────────── --}}
    <h2 class="rq-h2">{{ __('admin.safety.reports') }}</h2>

    @error('reports') <p class="rq-error" style="margin-bottom:14px">{{ $message }}</p> @enderror

    @forelse ($reports as $report)
        @php($overdue = $report->sla_due_at !== null && $report->sla_due_at->isPast())
        <article class="rq-card {{ $overdue ? 'rq-card--alert' : '' }}" wire:key="report-{{ $report->id }}">
            <div style="display:flex;align-items:center;gap:12px">
                <div style="flex:1;min-width:0">
                    <div style="font-weight:600">
                        {{ __('admin.safety.categories.'.$report->category->value) }}
                        <span class="rq-mono rq-note">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($report->id, -6)) }}</span>
                    </div>
                    <div class="rq-note">
                        {!! __('admin.safety.reported_by', [
                            'reporter' => '<bdi>'.e($report->reporter?->full_name ?? '—').'</bdi>',
                            'reported' => '<bdi>'.e($report->reportedUser?->full_name ?? __('admin.safety.nobody_named')).'</bdi>',
                        ]) !!}
                    </div>
                </div>
                <span class="rq-pill rq-pill--{{ in_array($report->severity->value, ['critical', 'high'], true) ? 'bad' : 'warn' }}">
                    {{ __('admin.safety.severity.'.$report->severity->value) }}
                </span>
                <span class="rq-pill rq-pill--count">{{ __('admin.safety.status.'.$report->status->value) }}</span>
            </div>

            @if ($report->description)
                <p style="margin:12px 0 0;font-size:13.5px;white-space:pre-line" dir="auto">{{ $report->description }}</p>
            @endif

            <dl class="rq-facts">
                <div>
                    <dt>{{ __('admin.safety.due') }}</dt>
                    <dd @if ($overdue) style="color:var(--bad-600);font-weight:700" @endif>
                        {{ $report->sla_due_at?->timezone('Africa/Cairo')->format('D H:i') ?? '—' }}
                        ({{ $report->sla_due_at?->diffForHumans() ?? '—' }})
                    </dd>
                </div>
                <div>
                    <dt>{{ __('admin.safety.handled_by') }}</dt>
                    <dd>{{ $report->assignedAdmin?->name ?? __('admin.safety.unassigned') }}</dd>
                </div>
                <div>
                    <dt>{{ __('admin.safety.evidence') }}</dt>
                    <dd>
                        {{ trans_choice('admin.safety.files', $report->evidence_count, ['count' => $report->evidence_count]) }}
                        @if (auth('admin')->user()?->can('safety.view_evidence'))
                            @foreach ($report->evidence as $file)
                                {{-- Opened through the session, checked against its hash, audited. --}}
                                · <a href="{{ route('admin.files.evidence', $file->id) }}" target="_blank" rel="noopener">{{ __('admin.files.open', ['what' => $loop->iteration]) }}</a>
                            @endforeach
                        @endif
                    </dd>
                </div>
            </dl>

            @if ($mayAct)
                <div class="rq-actions">
                    @if ($report->assigned_admin_id !== auth('admin')->id())
                        <button type="button" class="rq-btn rq-btn--primary"
                                wire:click="take('{{ $report->id }}')"
                                wire:loading.attr="disabled"
                                wire:target="take('{{ $report->id }}')">
                            {{ $report->assigned_admin_id === null ? __('admin.safety.take') : __('admin.safety.take_over') }}
                        </button>
                    @endif
                    @if ($report->status->value !== 'escalated')
                        <button type="button" class="rq-btn rq-btn--quiet" wire:click="openDecision('{{ $report->id }}', 'escalate')">
                            {{ __('admin.safety.escalate') }}
                        </button>
                    @endif
                    <button type="button" class="rq-btn rq-btn--quiet" wire:click="openDecision('{{ $report->id }}', 'resolve')">
                        {{ __('admin.safety.resolve') }}
                    </button>
                    <button type="button" class="rq-btn rq-btn--quiet" wire:click="openDecision('{{ $report->id }}', 'close')">
                        {{ __('admin.safety.close') }}
                    </button>
                    @if ($report->reported_user_id !== null && auth('admin')->user()?->can('member.view'))
                        {{-- Where "freeze this account" happens: a hold has its own case number and review time. --}}
                        <a class="rq-btn rq-btn--quiet" href="{{ route('admin.members', ['member' => $report->reported_user_id]) }}" wire:navigate>
                            {{ __('admin.safety.open_member') }}
                        </a>
                    @endif
                </div>

                @if ($openReport === $report->id)
                    <form wire:submit="decide('{{ $report->id }}')" style="margin-top:13px">
                        {{--
                            🔒 For resolve and close this text is returned to the REPORTER verbatim
                            (`IncidentResource::resolution`), so the prompt says so. For escalate it
                            is internal and only the audit trail keeps it.
                        --}}
                        <p class="rq-note" style="margin:0 0 6px">{{ __('admin.safety.hint_'.$step) }}</p>
                        <textarea class="rq-field" wire:model="reply" rows="3" required></textarea>
                        @error('reply') <p class="rq-error">{{ $message }}</p> @enderror
                        @error('step') <p class="rq-error">{{ $message }}</p> @enderror
                        <div class="rq-actions">
                            <button type="submit" class="rq-btn {{ $step === 'escalate' ? 'rq-btn--danger' : 'rq-btn--primary' }}" wire:loading.attr="disabled">
                                {{ __('admin.safety.confirm_'.$step) }}
                            </button>
                            <button type="button" class="rq-btn rq-btn--quiet" wire:click="$set('openReport', null)">
                                {{ __('admin.queue.cancel') }}
                            </button>
                        </div>
                    </form>
                @endif
            @else
                <p class="rq-note" style="margin:14px 0 0">{{ __('admin.queue.view_only') }}</p>
            @endif
        </article>
    @empty
        <p class="rq-empty">{{ __('admin.safety.no_reports') }}</p>
    @endforelse

    <x-rq-pager :page="$reports" position="admin.pager.position_open" />
</div>
