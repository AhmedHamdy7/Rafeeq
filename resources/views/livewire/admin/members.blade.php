{{--
    MEMBERS. Names and addresses members typed are wrapped in <bdi>, so Arabic does not
    reorder the English line around it (the same fix as the live board).
--}}
@php
    use App\Domains\Shared\ValueObjects\PhoneNumber;
@endphp
<div>
    <h1 class="rq-h1">{{ __('admin.members.title') }}</h1>
    <p class="rq-sub">{{ trans_choice('admin.members.count', $members->total(), ['count' => $members->total()]) }}</p>

    <div class="rq-filters" role="tablist">
        @foreach (\App\Domains\Admin\Support\MemberDirectory::TABS as $name)
            <button type="button" role="tab"
                    class="rq-btn {{ $tab === $name ? 'rq-btn--primary' : 'rq-btn--quiet' }}"
                    aria-selected="{{ $tab === $name ? 'true' : 'false' }}"
                    wire:click="pick('{{ $name }}')">
                {{ __('admin.members.tabs.'.$name) }}
            </button>
        @endforeach
    </div>

    <input type="search" class="rq-field" style="margin-bottom:14px"
           wire:model.live.debounce.400ms="search"
           placeholder="{{ __('admin.members.search') }}"
           aria-label="{{ __('admin.members.search') }}">

    @forelse ($members as $member)
        @php
            $suspension = $member->activeSuspension;
            $isDriver = $member->driverProfile?->status?->value === 'approved';
            $overdue = $suspension?->isOverdue() ?? false;
        @endphp
        <article class="rq-card {{ $overdue ? 'rq-card--alert' : '' }}" wire:key="member-{{ $member->id }}">
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <span class="rq-avatar">{{ mb_substr($member->full_name ?? '?', 0, 1) }}</span>
                <div style="flex:1;min-width:180px">
                    <div style="font-weight:600"><bdi>{{ $member->full_name ?? '—' }}</bdi></div>
                    <div class="rq-note">
                        {{-- 🔒 Masked, always — see MemberDirectory. --}}
                        <span class="rq-mono" dir="ltr">{{ PhoneNumber::fromRaw($member->phone_e164)->masked() }}</span>
                        &middot; {{ $isDriver ? __('admin.members.role_driver') : __('admin.members.role_rider') }}
                        &middot; {{ trans_choice('admin.members.trips', ($member->stats?->completed_trips_as_driver ?? 0) + ($member->stats?->completed_trips_as_passenger ?? 0), ['count' => ($member->stats?->completed_trips_as_driver ?? 0) + ($member->stats?->completed_trips_as_passenger ?? 0)]) }}
                        @php($rating = $isDriver ? $member->stats?->avg_rating_as_driver : $member->stats?->avg_rating_as_passenger)
                        &middot; {{ $rating === null ? __('admin.members.no_rating') : '★ '.number_format((float) $rating, 2) }}
                    </div>
                </div>
                @if ($member->open_reports_count > 0)
                    <span class="rq-pill rq-pill--warn">{{ trans_choice('admin.members.open_reports', $member->open_reports_count, ['count' => $member->open_reports_count]) }}</span>
                @endif
                @if ($member->account_status->value === 'suspended')
                    <span class="rq-pill rq-pill--bad">
                        {{ $overdue ? __('admin.members.review_overdue') : __('admin.members.on_hold') }}
                        @if ($suspension) &middot; <span class="rq-mono">{{ $suspension->case_number }}</span> @endif
                    </span>
                @else
                    <span class="rq-pill rq-pill--ok">{{ __('admin.members.active') }}</span>
                @endif
            </div>

            <div class="rq-actions">
                <button type="button" class="rq-btn rq-btn--quiet" wire:click="open('{{ $member->id }}')">
                    {{ $openId === $member->id ? __('admin.trips.hide') : __('admin.members.profile') }}
                </button>
            </div>

            @if ($opened?->id === $member->id)
                <dl class="rq-facts">
                    <div><dt>{{ __('admin.members.since') }}</dt><dd>{{ $opened->created_at->timezone('Africa/Cairo')->format('j M Y') }}</dd></div>
                    <div><dt>{{ __('admin.members.trust_level') }}</dt><dd>{{ $opened->trust_level }} / 4</dd></div>
                    <div><dt>{{ __('admin.members.no_shows') }}</dt><dd>{{ $opened->stats?->no_show_count ?? 0 }}</dd></div>
                    @if ($isDriver)
                        <div><dt>{{ __('admin.members.on_time') }}</dt><dd>{{ $opened->stats?->on_time_rate === null ? '—' : number_format((float) $opened->stats->on_time_rate).'%' }}</dd></div>
                    @endif
                    @if ($suspension)
                        <div><dt>{{ __('admin.members.hold_reason') }}</dt><dd>{{ __('admin.members.reasons.'.$suspension->reason_code->value) }}</dd></div>
                        <div>
                            <dt>{{ __('admin.members.review_by') }}</dt>
                            <dd @if ($overdue) style="color:var(--bad-600);font-weight:700" @endif>
                                {{ $suspension->review_due_at->timezone('Africa/Cairo')->format('D j M H:i') }} ({{ $suspension->review_due_at->diffForHumans() }})
                            </dd>
                        </div>
                    @endif
                </dl>

                @if ($openReports->isNotEmpty())
                    <h2 class="rq-h2" style="margin-top:16px">{{ __('admin.members.reports_about') }}</h2>
                    <ul class="rq-checks">
                        @foreach ($openReports as $report)
                            <li class="rq-check">
                                <span class="rq-check__label">{{ __('admin.safety.categories.'.$report->category->value) }} <span class="rq-mono rq-note">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($report->id, -6)) }}</span></span>
                                <span>{{ __('admin.safety.status.'.$report->status->value) }}</span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($maySeeSafety)
                        <a class="rq-btn rq-btn--quiet" href="{{ route('admin.safety') }}" wire:navigate style="margin-top:8px;display:inline-flex">{{ __('admin.safety.open') }}</a>
                    @endif
                @endif

                @if ($opened->suspensions->isNotEmpty())
                    <h2 class="rq-h2" style="margin-top:16px">{{ __('admin.members.history') }}</h2>
                    <ul class="rq-checks">
                        @foreach ($opened->suspensions as $past)
                            <li class="rq-check">
                                <span class="rq-check__label">
                                    <span class="rq-mono">{{ $past->case_number }}</span>
                                    &middot; {{ __('admin.members.reasons.'.$past->reason_code->value) }}
                                </span>
                                <span class="rq-note">
                                    {{ $past->suspended_at->timezone('Africa/Cairo')->format('j M Y') }} · {{ $past->suspendedBy?->name ?? '—' }}
                                    @if ($past->lifted_at)
                                        → {{ __('admin.members.lifted', ['date' => $past->lifted_at->timezone('Africa/Cairo')->format('j M Y'), 'name' => $past->liftedBy?->name ?? '—']) }}
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($mayAct)
                    <div class="rq-actions">
                        @if ($opened->account_status->value === 'active')
                            <button type="button" class="rq-btn rq-btn--danger" wire:click="startForm('suspend')">{{ __('admin.members.suspend') }}</button>
                        @elseif ($opened->account_status->value === 'suspended')
                            <button type="button" class="rq-btn rq-btn--approve" wire:click="startForm('reinstate')">{{ __('admin.members.reinstate') }}</button>
                        @endif
                    </div>

                    @if ($mode === 'suspend')
                        <form wire:submit="suspend" style="margin-top:13px">
                            <label class="rq-label" for="reason-{{ $opened->id }}">{{ __('admin.members.reason') }}</label>
                            <select id="reason-{{ $opened->id }}" class="rq-field" wire:model="reasonCode" required>
                                <option value="">—</option>
                                @foreach ($reasons as $reason)
                                    <option value="{{ $reason->value }}">{{ __('admin.members.reasons.'.$reason->value) }}</option>
                                @endforeach
                            </select>
                            @error('reasonCode') <p class="rq-error">{{ $message }}</p> @enderror
                            {{-- The member sees this sentence — not the note below. --}}
                            <p class="rq-note" style="margin:6px 0 0">{{ __('admin.members.reason_hint') }}</p>

                            @if ($openReports->isNotEmpty())
                                <label class="rq-label" for="incident-{{ $opened->id }}" style="margin-top:10px">{{ __('admin.members.linked_report') }}</label>
                                <select id="incident-{{ $opened->id }}" class="rq-field" wire:model="incidentId">
                                    <option value="">—</option>
                                    @foreach ($openReports as $report)
                                        <option value="{{ $report->id }}">{{ __('admin.safety.categories.'.$report->category->value) }} · {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($report->id, -6)) }}</option>
                                    @endforeach
                                </select>
                            @endif

                            <textarea class="rq-field" wire:model="note" rows="3" required style="margin-top:10px"
                                      placeholder="{{ __('admin.members.note_placeholder') }}"></textarea>
                            @error('note') <p class="rq-error">{{ $message }}</p> @enderror

                            @if ($strandedDays > 0)
                                {{-- Shown before the button, because the hold does not cancel these. --}}
                                <p class="rq-error" style="margin-top:10px">{{ trans_choice('admin.members.stranded', $strandedDays, ['count' => $strandedDays]) }}</p>
                            @endif

                            <div class="rq-actions">
                                <button type="submit" class="rq-btn rq-btn--danger" wire:loading.attr="disabled">{{ __('admin.members.confirm_suspend') }}</button>
                                <button type="button" class="rq-btn rq-btn--quiet" wire:click="startForm('')">{{ __('admin.queue.cancel') }}</button>
                            </div>
                        </form>
                    @elseif ($mode === 'reinstate')
                        <form wire:submit="reinstate" style="margin-top:13px">
                            <textarea class="rq-field" wire:model="note" rows="3" required
                                      placeholder="{{ __('admin.members.reinstate_placeholder') }}"></textarea>
                            @error('note') <p class="rq-error">{{ $message }}</p> @enderror
                            <div class="rq-actions">
                                <button type="submit" class="rq-btn rq-btn--approve" wire:loading.attr="disabled">{{ __('admin.members.confirm_reinstate') }}</button>
                                <button type="button" class="rq-btn rq-btn--quiet" wire:click="startForm('')">{{ __('admin.queue.cancel') }}</button>
                            </div>
                        </form>
                    @endif
                @endif
            @endif
        </article>
    @empty
        <p class="rq-empty">{{ __('admin.members.empty') }}</p>
    @endforelse

    <x-rq-pager :page="$members" position="admin.pager.position_members" />
</div>
