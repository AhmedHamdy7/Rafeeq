{{--
    USERS (U01–U05, D01, D11–D13, D25, PH3). A table of members (cards on a phone); a row
    opens the member in a side panel over the list, with every decision the page had.

    Names and addresses members typed are wrapped in <bdi>, so Arabic does not reorder the
    English line around it. 🔒 Phones are masked, always — see MemberDirectory.
--}}
@php
    use App\Domains\Shared\ValueObjects\PhoneNumber;
    use Illuminate\Support\Str;
@endphp
<div>
    <x-admin.page-head :title="__('admin.members.title')" :sub="trans_choice('admin.members.count', $members->total(), ['count' => $members->total()])" />

    <div class="ad-tabs" role="tablist">
        @foreach (\App\Domains\Admin\Support\MemberDirectory::TABS as $name)
            <button type="button" role="tab" class="ad-tab"
                    aria-selected="{{ $tab === $name ? 'true' : 'false' }}"
                    wire:click="pick('{{ $name }}')">
                {{ __('admin.members.tabs.'.$name) }}
            </button>
        @endforeach
    </div>

    <div class="ad-toolbar">
        <label class="ad-search" style="inline-size:min(420px,100%)">
            <x-admin.icon name="search" />
            <input type="search"
                   wire:model.live.debounce.400ms="search"
                   placeholder="{{ __('admin.members.search') }}"
                   aria-label="{{ __('admin.members.search') }}">
        </label>
        <span wire:loading.delay class="ad-muted" style="font-size:12px">{{ __('admin/shell.states.loading') }}…</span>
    </div>

    <x-admin.card flush>
        @if ($members->isEmpty())
            <x-admin.state kind="empty" :title="__('admin.members.empty')" :text="$search !== '' ? __('admin/members.no_match', ['search' => $search]) : null" />
        @else
            <div class="ad-table-wrap">
                <table class="ad-table ad-table--cards">
                    <thead>
                    <tr>
                        <th>{{ __('admin/members.name') }}</th>
                        <th>{{ __('admin/members.phone') }}</th>
                        <th>{{ __('admin/members.account') }}</th>
                        <th class="ad-end">{{ __('admin/members.trips') }}</th>
                        <th class="ad-end">{{ __('admin/members.rating') }}</th>
                        <th class="ad-end">{{ __('admin/members.open_reports') }}</th>
                        <th>{{ __('admin/members.joined') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($members as $member)
                        @php
                            $suspension = $member->activeSuspension;
                            $isDriver = $member->driverProfile?->status?->value === 'approved';
                            $overdue = $suspension?->isOverdue() ?? false;
                            $trips = ($member->stats?->completed_trips_as_driver ?? 0) + ($member->stats?->completed_trips_as_passenger ?? 0);
                            $rating = $isDriver ? $member->stats?->avg_rating_as_driver : $member->stats?->avg_rating_as_passenger;
                        @endphp
                        <tr class="ad-click" wire:key="member-{{ $member->id }}" wire:click="open('{{ $member->id }}')"
                            @if ($openId === $member->id) aria-selected="true" @endif
                            @class(['ad-row--flagged' => $overdue])>
                            <td data-label="{{ __('admin/members.name') }}">
                                <div class="ad-row" style="gap:8px;flex-wrap:nowrap">
                                    <span class="ad-avatar" aria-hidden="true">{{ mb_substr($member->full_name ?? '?', 0, 1) }}</span>
                                    {{-- A real button, so the row opens from the keyboard too. --}}
                                    <button type="button" class="ad-link" style="background:none;border:none;padding:0;cursor:pointer;text-align:start"
                                            wire:click.stop="open('{{ $member->id }}')">
                                        <bdi>{{ $member->full_name ?? '—' }}</bdi>
                                    </button>
                                    <span class="ad-chip ad-chip--{{ $isDriver ? 'brand' : 'info' }} ad-chip--plain">{{ $isDriver ? __('admin.members.role_driver') : __('admin.members.role_rider') }}</span>
                                </div>
                            </td>
                            <td data-label="{{ __('admin/members.phone') }}"><span class="ad-mono" dir="ltr">{{ PhoneNumber::fromRaw($member->phone_e164)->masked() }}</span></td>
                            <td data-label="{{ __('admin/members.account') }}">
                                @if ($member->account_status->value === 'suspended')
                                    <span class="ad-chip ad-chip--bad">
                                        {{ $overdue ? __('admin.members.review_overdue') : __('admin.members.on_hold') }}
                                        @if ($suspension) · <span class="ad-mono">{{ $suspension->case_number }}</span> @endif
                                    </span>
                                @else
                                    <span class="ad-chip ad-chip--ok">{{ __('admin.members.active') }}</span>
                                @endif
                            </td>
                            <td data-label="{{ __('admin/members.trips') }}" class="ad-end ad-num">{{ $trips }}</td>
                            <td data-label="{{ __('admin/members.rating') }}" class="ad-end ad-num">{{ $rating === null ? '—' : number_format((float) $rating, 1) }}</td>
                            <td data-label="{{ __('admin/members.open_reports') }}" class="ad-end ad-num" @if ($member->open_reports_count > 0) style="color:var(--danger-600);font-weight:700" @endif>
                                @if ($member->open_reports_count > 0)
                                    {{ trans_choice('admin.members.open_reports', $member->open_reports_count, ['count' => $member->open_reports_count]) }}
                                @else
                                    0
                                @endif
                            </td>
                            <td data-label="{{ __('admin/members.joined') }}" class="ad-muted">{{ $member->created_at?->timezone('Africa/Cairo')->format('j M Y') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-admin.card>

    <x-rq-pager :page="$members" position="admin.pager.position_members" />

    {{-- ─────────── The member, in a side panel (D01) ─────────── --}}
    @if ($opened !== null)
        @php
            $suspension = $opened->activeSuspension;
            $isDriver = $opened->driverProfile?->status?->value === 'approved';
            $overdue = $suspension?->isOverdue() ?? false;
        @endphp
        <div class="ad-dim" style="place-items:stretch end;padding:0" wire:click.self="open(null)" x-data x-on:keydown.escape.window="$wire.open(null)">
            <aside class="ad-drawer" role="dialog" aria-modal="true" aria-labelledby="member-title" wire:key="drawer-{{ $opened->id }}">
                <div class="ad-row" style="align-items:flex-start;gap:12px;margin-block-end:14px">
                    <span class="ad-avatar" style="inline-size:48px;block-size:48px;font-size:18px" aria-hidden="true">{{ mb_substr($opened->full_name ?? '?', 0, 1) }}</span>
                    <div style="flex:1;min-inline-size:0">
                        <h2 class="ad-modal__title" id="member-title" style="margin:0"><bdi>{{ $opened->full_name ?? '—' }}</bdi></h2>
                        <div class="ad-row" style="gap:6px;margin-block-start:6px">
                            @if ($opened->account_status->value === 'suspended')
                                <span class="ad-chip ad-chip--bad">{{ $overdue ? __('admin.members.review_overdue') : __('admin.members.on_hold') }}</span>
                            @else
                                <span class="ad-chip ad-chip--ok">{{ __('admin.members.active') }}</span>
                            @endif
                            <span class="ad-chip ad-chip--plain">{{ $isDriver ? __('admin.members.role_driver') : __('admin.members.role_rider') }}</span>
                            @if ($opened->activeSuspension?->is_permanent)
                                <span class="ad-chip ad-chip--bad ad-chip--plain">{{ __('admin.members.permanent') }}</span>
                            @endif
                        </div>
                    </div>
                    <button type="button" class="ad-icon-btn" wire:click="open(null)">
                        <x-admin.icon name="x" size="18" />
                        <span class="ad-sr">{{ __('admin/members.close') }}</span>
                    </button>
                </div>

                @if (session('status'))
                    <p class="ad-flash" role="status"><x-admin.icon name="check" />{{ session('status') }}</p>
                @endif

                @if ($driverSuspended)
                    <div class="ad-notice ad-notice--warn" style="margin-block-end:12px"><x-admin.icon name="alert" />{{ __('admin.members.driver_is_suspended') }}</div>
                @endif

                <dl class="ad-kv">
                    <dt>{{ __('admin.members.since') }}</dt><dd>{{ $opened->created_at->timezone('Africa/Cairo')->format('j M Y') }}</dd>
                    <dt>{{ __('admin/members.phone') }}</dt><dd><span class="ad-mono" dir="ltr">{{ PhoneNumber::fromRaw($opened->phone_e164)->masked() }}</span></dd>
                    <dt>{{ __('admin.members.trust_level') }}</dt><dd>{{ $opened->trust_level }} / 4</dd>
                    <dt>{{ __('admin.members.no_shows') }}</dt><dd>{{ $opened->stats?->no_show_count ?? 0 }}</dd>
                    @if ($isDriver)
                        <dt>{{ __('admin.members.on_time') }}</dt><dd>{{ $opened->stats?->on_time_rate === null ? '—' : number_format((float) $opened->stats->on_time_rate).'%' }}</dd>
                    @endif
                    @if ($suspension)
                        <dt>{{ __('admin.members.hold_reason') }}</dt><dd>{{ __('admin.members.reasons.'.$suspension->reason_code->value) }} · <span class="ad-mono">{{ $suspension->case_number }}</span></dd>
                        <dt>{{ __('admin.members.review_by') }}</dt>
                        <dd @if ($overdue) style="color:var(--danger-600);font-weight:700" @endif>
                            {{ $suspension->review_due_at->timezone('Africa/Cairo')->format('D j M H:i') }} ({{ $suspension->review_due_at->diffForHumans() }})
                        </dd>
                    @endif
                </dl>

                <details wire:ignore.self class="ad-details">
                    <summary class="ad-btn ad-btn--sm">{{ __('admin.details.show_all') }}</summary>
                    <x-rq-member-details :member="$opened" :may-see-documents="$maySeeDocuments"
                                         :revealed="$revealedId === $opened->id" />
                </details>

                {{-- Her appeals against the hold (F1-3). Overturning lifts it. --}}
                @if ($opened->activeSuspension?->appeals?->isNotEmpty())
                    <div class="ad-section-label">{{ __('admin.members.appeals') }}</div>
                    <div class="ad-stack">
                        @foreach ($opened->activeSuspension->appeals as $appeal)
                            <div class="ad-notice">
                                <div style="flex:1;min-inline-size:0">
                                    <strong>#{{ $appeal->attempt }} · {{ __('admin.members.appeal_status.'.($appeal->resolution?->value ?? 'pending')) }}</strong>
                                    <span class="ad-muted"> · {{ $appeal->created_at->timezone('Africa/Cairo')->format('j M H:i') }}</span>
                                    <p style="margin:6px 0 0;white-space:pre-line" dir="auto">{{ $appeal->message }}</p>
                                    @if ($appeal->evidence)
                                        <p class="ad-muted" style="margin:6px 0 0"><strong>{{ __('admin.members.evidence') }}:</strong> {{ $appeal->evidence }}</p>
                                    @endif
                                    @if ($mayAct && $appeal->resolution === null)
                                        <textarea class="ad-textarea" wire:model="note" rows="2" style="margin-block-start:8px"
                                                  aria-label="{{ __('admin.members.appeal_note_placeholder') }}"
                                                  placeholder="{{ __('admin.members.appeal_note_placeholder') }}"></textarea>
                                        @error('note') <span class="ad-error">{{ $message }}</span> @enderror
                                        <div class="ad-row" style="margin-block-start:8px">
                                            <button type="button" class="ad-btn ad-btn--primary ad-btn--sm" wire:click="decideAppeal('{{ $appeal->id }}', true)" wire:loading.attr="disabled">{{ __('admin.members.appeal_overturn') }}</button>
                                            <button type="button" class="ad-btn ad-btn--sm" wire:click="decideAppeal('{{ $appeal->id }}', false)" wire:loading.attr="disabled">{{ __('admin.members.appeal_uphold') }}</button>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($openReports->isNotEmpty())
                    <div class="ad-section-label">{{ __('admin.members.reports_about') }}</div>
                    <table class="ad-table ad-table--dense">
                        <tbody>
                        @foreach ($openReports as $report)
                            <tr>
                                <td>{{ __('admin.safety.categories.'.$report->category->value) }} <span class="ad-code">{{ Str::upper(Str::substr($report->id, -6)) }}</span></td>
                                <td class="ad-end"><span class="ad-chip ad-chip--plain">{{ __('admin.safety.status.'.$report->status->value) }}</span></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    @if ($maySeeSafety)
                        <a class="ad-btn ad-btn--sm ad-btn--quiet" href="{{ route('admin.safety') }}" wire:navigate style="margin-block-start:8px">{{ __('admin.safety.open') }}</a>
                    @endif
                @endif

                @if ($opened->suspensions->isNotEmpty())
                    <div class="ad-section-label">{{ __('admin.members.history') }}</div>
                    <table class="ad-table ad-table--dense">
                        <tbody>
                        @foreach ($opened->suspensions as $past)
                            <tr>
                                <td><span class="ad-mono">{{ $past->case_number }}</span> · {{ __('admin.members.reasons.'.$past->reason_code->value) }}</td>
                                <td class="ad-muted">
                                    {{ $past->suspended_at->timezone('Africa/Cairo')->format('j M Y') }} · {{ $past->suspendedBy?->name ?? '—' }}
                                    @if ($past->lifted_at)
                                        → {{ __('admin.members.lifted', ['date' => $past->lifted_at->timezone('Africa/Cairo')->format('j M Y'), 'name' => $past->liftedBy?->name ?? '—']) }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif

                @if ($mayAct)
                    <div class="ad-section-label">{{ __('admin/members.decisions') }}</div>
                    <div class="ad-row">
                        @if ($opened->account_status->value === 'active')
                            <button type="button" class="ad-btn ad-btn--dangerline" wire:click="startForm('suspend')">{{ __('admin.members.suspend') }}</button>
                        @elseif ($opened->account_status->value === 'suspended')
                            <button type="button" class="ad-btn ad-btn--primary" wire:click="startForm('reinstate')">{{ __('admin.members.reinstate') }}</button>
                        @endif
                        @if ($driverSuspendable)
                            <button type="button" class="ad-btn ad-btn--dangerline" wire:click="startForm('suspend_driver')">{{ __('admin.members.suspend_driver') }}</button>
                        @endif
                    </div>

                    @if ($mode === 'suspend')
                        {{-- D11: what the hold does, before the button that does it. --}}
                        <form wire:submit="suspend" class="ad-confirm">
                            <div class="ad-field">
                                <label class="ad-label" for="reason-{{ $opened->id }}">{{ __('admin.members.reason') }}</label>
                                <select id="reason-{{ $opened->id }}" class="ad-select" wire:model="reasonCode" required>
                                    <option value="">—</option>
                                    @foreach ($reasons as $reason)
                                        <option value="{{ $reason->value }}">{{ __('admin.members.reasons.'.$reason->value) }}</option>
                                    @endforeach
                                </select>
                                @error('reasonCode') <span class="ad-error">{{ $message }}</span> @enderror
                                {{-- The member sees this sentence — not the note below. --}}
                                <span class="ad-hint">{{ __('admin.members.reason_hint') }}</span>
                            </div>

                            @if ($openReports->isNotEmpty())
                                <div class="ad-field">
                                    <label class="ad-label" for="incident-{{ $opened->id }}">{{ __('admin.members.linked_report') }}</label>
                                    <select id="incident-{{ $opened->id }}" class="ad-select" wire:model="incidentId">
                                        <option value="">—</option>
                                        @foreach ($openReports as $report)
                                            <option value="{{ $report->id }}">{{ __('admin.safety.categories.'.$report->category->value) }} · {{ Str::upper(Str::substr($report->id, -6)) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif

                            <div class="ad-field">
                                <label class="ad-label" for="note-{{ $opened->id }}">{{ __('admin.members.note') }}</label>
                                <textarea id="note-{{ $opened->id }}" class="ad-textarea" wire:model="note" rows="3" required
                                          placeholder="{{ __('admin.members.note_placeholder') }}"></textarea>
                                @error('note') <span class="ad-error">{{ $message }}</span> @enderror
                            </div>

                            {{-- AUTH-19: a suspension stays in the app with Appeal; only a ban shows the restricted screen. --}}
                            <label class="ad-check"><input type="checkbox" wire:model="permanent"> {{ __('admin.members.permanent_label') }}</label>
                            <p class="ad-hint" style="margin:0 0 10px">{{ __('admin.members.permanent_hint') }}</p>

                            @if ($strandedDays > 0)
                                {{-- Shown before the button, because the hold cancels these (ADM-08). --}}
                                <div class="ad-notice ad-notice--warn" style="margin-block-end:10px"><x-admin.icon name="alert" />{{ trans_choice('admin.members.stranded', $strandedDays, ['count' => $strandedDays]) }}</div>
                            @endif

                            <div class="ad-row">
                                <button type="submit" class="ad-btn ad-btn--danger" wire:loading.attr="disabled">{{ __('admin.members.confirm_suspend') }}</button>
                                <button type="button" class="ad-btn" wire:click="startForm('')">{{ __('admin.queue.cancel') }}</button>
                            </div>
                        </form>
                    @elseif ($mode === 'suspend_driver')
                        {{-- D12: the driver role only; the account and her bookings as a passenger stay. --}}
                        <form wire:submit="suspendDriver" class="ad-confirm">
                            <div class="ad-field">
                                <textarea class="ad-textarea" wire:model="note" rows="3" required
                                          aria-label="{{ __('admin.members.driver_suspend_placeholder') }}"
                                          placeholder="{{ __('admin.members.driver_suspend_placeholder') }}"></textarea>
                                @error('note') <span class="ad-error">{{ $message }}</span> @enderror
                                <span class="ad-hint">{{ __('admin.members.driver_suspend_hint') }}</span>
                            </div>
                            @if ($strandedDays > 0)
                                <div class="ad-notice ad-notice--warn" style="margin-block-end:10px"><x-admin.icon name="alert" />{{ __('admin.members.driver_suspend_impact', ['days' => $strandedDays, 'passengers' => $strandedPassengers]) }}</div>
                            @endif
                            <div class="ad-row">
                                <button type="submit" class="ad-btn ad-btn--danger" wire:loading.attr="disabled">{{ __('admin.members.confirm_suspend_driver') }}</button>
                                <button type="button" class="ad-btn" wire:click="startForm('')">{{ __('admin.queue.cancel') }}</button>
                            </div>
                        </form>
                    @elseif ($mode === 'reinstate')
                        {{-- D13. --}}
                        <form wire:submit="reinstate" class="ad-confirm">
                            <div class="ad-field">
                                <textarea class="ad-textarea" wire:model="note" rows="3" required
                                          aria-label="{{ __('admin.members.reinstate_placeholder') }}"
                                          placeholder="{{ __('admin.members.reinstate_placeholder') }}"></textarea>
                                @error('note') <span class="ad-error">{{ $message }}</span> @enderror
                            </div>
                            <div class="ad-row">
                                <button type="submit" class="ad-btn ad-btn--primary" wire:loading.attr="disabled">{{ __('admin.members.confirm_reinstate') }}</button>
                                <button type="button" class="ad-btn" wire:click="startForm('')">{{ __('admin.queue.cancel') }}</button>
                            </div>
                        </form>
                    @endif
                @else
                    <p class="ad-hint" style="margin-block-start:16px">{{ __('admin.queue.view_only') }}</p>
                @endif
            </aside>
        </div>
    @endif
</div>
