{{-- SUPPORT CASE (X02). Open → In progress → Resolved → Closed; the same actions as the list. --}}
@php
    use Illuminate\Support\Facades\Lang;

    $priority = $ticket->priority->value;
    $done = in_array($ticket->status->value, ['resolved', 'closed'], true);
    $overdue = $ticket->sla_due_at !== null && $ticket->sla_due_at->isPast() && ! $done;
@endphp
<div>
    <nav class="ad-muted" style="font-size:12.5px;margin-block-end:6px">
        <a class="ad-link" href="{{ route('admin.support') }}" wire:navigate>{{ __('admin/support.title') }}</a>
        <x-admin.icon name="chevron" size="12" class="ad-flip" />
        <span class="ad-code">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($ticket->id, -6)) }}</span>
    </nav>

    <x-admin.page-head :title="Lang::has('admin/support.categories.'.$ticket->category) ? __('admin/support.categories.'.$ticket->category) : \Illuminate\Support\Str::headline($ticket->category)" :sub="$ticket->user?->full_name">
        @if ($mayAct && ! $done && $finishing !== $ticket->id)
            @if ($ticket->assigned_admin_id !== auth('admin')->id())
                <button type="button" class="ad-btn ad-btn--primary" wire:click="take('{{ $ticket->id }}')" wire:loading.attr="disabled">{{ __('admin/support.take') }}</button>
            @endif
            <button type="button" class="ad-btn" wire:click="startFinish('{{ $ticket->id }}', 'resolved')">{{ __('admin/support.resolve') }}</button>
            <button type="button" class="ad-btn ad-btn--quiet" wire:click="startFinish('{{ $ticket->id }}', 'closed')">{{ __('admin/support.close') }}</button>
        @endif
    </x-admin.page-head>

    @error('ticket') <p class="ad-flash ad-flash--error" role="alert"><x-admin.icon name="alert" />{{ $message }}</p> @enderror

    {{-- The four steps, with where this case is. --}}
    <ol class="ad-row" style="list-style:none;padding:0;margin:0 0 12px;gap:6px" aria-label="{{ __('admin/support.steps_label') }}">
        @foreach (\App\Livewire\Admin\Support::TABS as $stepName)
            <li><span @class(['ad-chip', 'ad-chip--ok' => $ticket->status->value === $stepName, 'ad-chip--plain' => $ticket->status->value !== $stepName])>{{ __('admin/support.statuses.'.$stepName) }}</span></li>
        @endforeach
    </ol>

    @if ($mayAct && $finishing === $ticket->id)
        <x-admin.card :title="$finishAs === 'closed' ? __('admin/support.close') : __('admin/support.resolve')" bar style="margin-block-end:12px">
            <form wire:submit="finish">
                <div class="ad-field">
                    <label class="ad-label" for="case-note">{{ __('admin/support.note') }}</label>
                    <textarea id="case-note" class="ad-textarea" wire:model="note" rows="3" required placeholder="{{ __('admin/support.note_placeholder') }}"></textarea>
                    @error('note') <span class="ad-error">{{ $message }}</span> @enderror
                </div>
                <div class="ad-row">
                    <button type="submit" class="ad-btn ad-btn--primary" wire:loading.attr="disabled">{{ $finishAs === 'closed' ? __('admin/support.confirm_close') : __('admin/support.confirm_resolve') }}</button>
                    <button type="button" class="ad-btn" wire:click="$set('finishing', null)">{{ __('admin.queue.cancel') }}</button>
                </div>
            </form>
        </x-admin.card>
    @endif

    <div class="ad-split">
        <div class="ad-stack">
            <x-admin.card :title="__('admin/support.case')" bar>
                <dl class="ad-facts" style="margin:0">
                    <div><dt>{{ __('admin/support.member') }}</dt><dd>@if ($ticket->user)@can('member.view')<a class="ad-link" href="{{ route('admin.members.show', $ticket->user->id) }}" wire:navigate><bdi>{{ $ticket->user->full_name }}</bdi></a>@else<bdi>{{ $ticket->user->full_name }}</bdi>@endcan @else — @endif</dd></div>
                    <div><dt>{{ __('admin/support.priority') }}</dt><dd><span class="ad-chip ad-chip--{{ ['urgent' => 'bad', 'high' => 'warn', 'medium' => 'info'][$priority] ?? 'plain' }}">{{ __('admin/support.priorities.'.$priority) }}</span></dd></div>
                    <div><dt>{{ __('admin/support.due') }}</dt><dd class="ad-num" @if ($overdue) style="color:var(--danger-600);font-weight:700" @endif>{{ $ticket->sla_due_at?->timezone('Africa/Cairo')->format('D j M H:i') ?? '—' }}</dd></div>
                    <div><dt>{{ __('admin/support.handled_by') }}</dt><dd>{{ $ticket->assignedAdmin?->name ?? __('admin/support.nobody') }}</dd></div>
                    <div><dt>{{ __('admin/reports.opened') }}</dt><dd class="ad-num">{{ $ticket->created_at?->timezone('Africa/Cairo')->format('j M Y H:i') }}</dd></div>
                </dl>
            </x-admin.card>

            <x-admin.card flush :title="__('admin/support.notes')" bar>
                @if ($notes->isEmpty())
                    <x-admin.state kind="empty" :title="__('admin/records.none')" />
                @else
                    <ul style="list-style:none;margin:0;padding:12px 16px;display:grid;gap:10px">
                        @foreach ($notes as $memberNote)
                            <li wire:key="n-{{ $memberNote->id }}"><div dir="auto" style="white-space:pre-line">{{ $memberNote->body }}</div><div class="ad-muted" style="font-size:12px">{{ $memberNote->admin?->name ?? '—' }} · {{ $memberNote->created_at?->timezone('Africa/Cairo')->format('j M H:i') }}</div></li>
                        @endforeach
                    </ul>
                @endif
            </x-admin.card>

            <x-admin.card flush :title="__('admin/support.open_reports')" bar>
                @if ($openReports->isEmpty())
                    <x-admin.state kind="empty" :title="__('admin/records.none')" />
                @else
                    <table class="ad-table ad-table--dense"><tbody>
                    @foreach ($openReports as $incident)
                        <tr wire:key="or-{{ $incident->id }}">
                            <td>@can('safety.view')<a class="ad-link" href="{{ route('admin.reports.show', $incident->id) }}" wire:navigate>{{ __('admin.safety.categories.'.$incident->category->value) }}</a>@else{{ __('admin.safety.categories.'.$incident->category->value) }}@endcan</td>
                            <td><span class="ad-chip">{{ __('admin.safety.status.'.$incident->status->value) }}</span></td>
                        </tr>
                    @endforeach
                    </tbody></table>
                @endif
            </x-admin.card>
        </div>

        <div class="ad-stack">
            <x-admin.card :title="__('admin/safety.timeline')" bar>
                <ol class="ad-timeline">
                    <li class="ad-timeline__item"><span class="ad-timeline__time ad-num">{{ $ticket->created_at?->timezone('Africa/Cairo')->format('j M H:i') }}</span><span class="ad-timeline__body"><strong>{{ __('admin/support.opened_event') }}</strong></span></li>
                    @foreach ($steps as $step)
                        @php($label = 'admin.audit.actions.'.str_replace('.', '__', $step->action))
                        <li class="ad-timeline__item">
                            <span class="ad-timeline__time ad-num">{{ $step->created_at?->timezone('Africa/Cairo')->format('j M H:i') }}</span>
                            <span class="ad-timeline__body"><strong>{{ Lang::has($label) ? __($label) : $step->action }}</strong> <span class="ad-muted" style="font-size:12px">· {{ $step->admin?->name ?? '—' }}</span>@if ($step->reason)<span class="ad-muted" style="display:block;font-size:12.5px" dir="auto">{{ $step->reason }}</span>@endif</span>
                        </li>
                    @endforeach
                </ol>
            </x-admin.card>
        </div>
    </div>
</div>
