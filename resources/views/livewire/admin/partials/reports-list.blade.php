{{--
    The open reports (R), oldest deadline first, with every decision: take, escalate, resolve,
    close, the money outcomes and women-only misuse. Drawn on the Reports page and, below the
    live alerts, on the SOS page. Needs: $reports (paginator), $mayAct, $openReport, $step.
--}}
    @error('reports') <p class="ad-flash ad-flash--error" role="alert"><x-admin.icon name="alert" />{{ $message }}</p> @enderror

    <div class="ad-stack">
        @forelse ($reports as $report)
            @php($overdue = $report->sla_due_at !== null && $report->sla_due_at->isPast())
            <x-admin.card flush :tone="$overdue ? 'topline-bad' : null" wire:key="report-{{ $report->id }}">
                <div class="ad-card__body">
                    <div class="ad-row" style="gap:10px">
                        <div style="flex:1;min-inline-size:180px">
                            <div style="font-weight:700">
                                <a class="ad-link" href="{{ route('admin.reports.show', $report->id) }}" wire:navigate>{{ __('admin.safety.categories.'.$report->category->value) }}</a>
                                <span class="ad-code">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($report->id, -6)) }}</span>
                            </div>
                            <div class="ad-muted" style="font-size:12.5px">
                                {!! __('admin.safety.reported_by', [
                                    'reporter' => '<bdi>'.e($report->reporter?->full_name ?? '—').'</bdi>',
                                    'reported' => '<bdi>'.e($report->reportedUser?->full_name ?? __('admin.safety.nobody_named')).'</bdi>',
                                ]) !!}
                            </div>
                        </div>
                        <span class="ad-chip ad-chip--{{ in_array($report->severity->value, ['critical', 'high'], true) ? 'bad' : 'warn' }}">
                            {{ __('admin.safety.severity.'.$report->severity->value) }}
                        </span>
                        <span class="ad-chip ad-chip--plain">{{ __('admin.safety.status.'.$report->status->value) }}</span>
                    </div>

                    @if ($report->description)
                        <p style="margin:12px 0 0;white-space:pre-line" dir="auto">{{ $report->description }}</p>
                    @endif

                    {{-- HISTORY-10: the decision she is appealing, and why, so the second look starts from both. --}}
                    @foreach ($report->appeals as $appeal)
                        <div class="ad-notice ad-notice--warn" style="margin-block-start:10px;white-space:pre-line" dir="auto">
                            <div>
                                <strong>{{ __('admin.safety.appeal_round', ['round' => $appeal->round]) }}</strong>
                                @if ($loop->last && $report->resolution)
                                    · {{ __('admin.safety.appeal_against', ['decision' => $report->resolution]) }}
                                @endif
                                <br>{{ $appeal->reason }}
                            </div>
                        </div>
                    @endforeach

                    <dl class="ad-kv" style="margin-block-start:14px">
                        <dt>{{ __('admin.safety.due') }}</dt>
                        <dd @if ($overdue) style="color:var(--danger-600);font-weight:700" @endif>
                            {{ $report->sla_due_at?->timezone('Africa/Cairo')->format('D H:i') ?? '—' }}
                            ({{ $report->sla_due_at?->diffForHumans() ?? '—' }})
                        </dd>
                        <dt>{{ __('admin.safety.handled_by') }}</dt>
                        <dd>{{ $report->assignedAdmin?->name ?? __('admin.safety.unassigned') }}</dd>
                        <dt>{{ __('admin.safety.evidence') }}</dt>
                        <dd>
                            {{ trans_choice('admin.safety.files', $report->evidence_count, ['count' => $report->evidence_count]) }}
                            @if (auth('admin')->user()?->can('safety.view_evidence'))
                                @foreach ($report->evidence as $file)
                                    {{-- Opened through the session, checked against its hash, audited. --}}
                                    · <a class="ad-link" href="{{ route('admin.files.evidence', $file->id) }}" target="_blank" rel="noopener">{{ __('admin.files.open', ['what' => $loop->iteration]) }}</a>
                                @endforeach
                            @endif
                        </dd>
                    </dl>

                    @if ($mayAct)
                        <div class="ad-row" style="margin-block-start:14px">
                            @if ($report->assigned_admin_id !== auth('admin')->id())
                                <button type="button" class="ad-btn ad-btn--primary ad-btn--sm"
                                        wire:click="take('{{ $report->id }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="take('{{ $report->id }}')">
                                    {{ $report->assigned_admin_id === null ? __('admin.safety.take') : __('admin.safety.take_over') }}
                                </button>
                            @endif
                            @if ($report->status->value !== 'escalated')
                                <button type="button" class="ad-btn ad-btn--sm" wire:click="openDecision('{{ $report->id }}', 'escalate')">{{ __('admin.safety.escalate') }}</button>
                            @endif
                            <button type="button" class="ad-btn ad-btn--sm" wire:click="openDecision('{{ $report->id }}', 'resolve')">{{ __('admin.safety.resolve') }}</button>
                            <button type="button" class="ad-btn ad-btn--sm" wire:click="openDecision('{{ $report->id }}', 'close')">{{ __('admin.safety.close') }}</button>
                            {{-- The money outcomes (F-11, F-12): each moves the money, then resolves the case. --}}
                            @if ($report->category->value === 'payment' && $report->booking_id !== null)
                                @foreach (['payment_passenger_lied', 'payment_driver_lied', 'payment_fee_line_not_received'] as $moneyStep)
                                    <button type="button" class="ad-btn ad-btn--sm" wire:click="openDecision('{{ $report->id }}', '{{ $moneyStep }}')">{{ __('admin.safety.'.$moneyStep) }}</button>
                                @endforeach
                            @endif
                            @if ($report->category->value === 'women_only_misuse' && $report->booking_id !== null)
                                <button type="button" class="ad-btn ad-btn--sm" wire:click="openDecision('{{ $report->id }}', 'women_only_confirmed')">{{ __('admin.safety.women_only_confirmed') }}</button>
                            @endif
                            @if ($report->reported_user_id !== null && auth('admin')->user()?->can('member.view'))
                                {{-- Where "freeze this account" happens: a hold has its own case number and review time. --}}
                                <a class="ad-btn ad-btn--sm ad-btn--quiet" href="{{ route('admin.members', ['member' => $report->reported_user_id]) }}" wire:navigate>{{ __('admin.safety.open_member') }}</a>
                            @endif
                        </div>

                        @if ($openReport === $report->id)
                            <form wire:submit="decide('{{ $report->id }}')" style="margin-block-start:14px">
                                {{--
                                    🔒 For resolve and close this text is returned to the REPORTER verbatim
                                    (`IncidentResource::resolution`), so the prompt says so. For escalate it
                                    is internal and only the audit trail keeps it.
                                --}}
                                <div class="ad-field">
                                    <span class="ad-hint">{{ __('admin.safety.hint_'.(in_array($step, ['escalate', 'close'], true) ? $step : 'resolve')) }}</span>
                                    <textarea class="ad-textarea" wire:model="reply" rows="3" required></textarea>
                                    @error('reply') <span class="ad-error">{{ $message }}</span> @enderror
                                    @error('step') <span class="ad-error">{{ $message }}</span> @enderror
                                </div>
                                <div class="ad-row">
                                    <button type="submit" class="ad-btn {{ $step === 'escalate' ? 'ad-btn--danger' : 'ad-btn--primary' }}" wire:loading.attr="disabled">
                                        {{ __('admin.safety.confirm_'.(in_array($step, ['escalate', 'close'], true) ? $step : 'resolve')) }}
                                    </button>
                                    <button type="button" class="ad-btn" wire:click="$set('openReport', null)">{{ __('admin.queue.cancel') }}</button>
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
                <x-admin.state kind="empty" :title="__('admin.safety.no_reports')" />
            </x-admin.card>
        @endforelse
    </div>

<x-rq-pager :page="$reports" position="admin.pager.position_open" />
