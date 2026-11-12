{{-- SUPPORT CASES (X). Most urgent and closest deadline first; take, resolve or close with a note. --}}
<div>
    <x-admin.page-head :title="__('admin/support.title')" :sub="__('admin/support.sub')" />

    <div class="ad-tabs" role="tablist">
        @foreach (\App\Livewire\Admin\Support::TABS as $name)
            <button type="button" role="tab" class="ad-tab" aria-selected="{{ $status === $name ? 'true' : 'false' }}" wire:click="pick('{{ $name }}')">
                {{ __('admin/support.statuses.'.$name) }} <span class="ad-badge">{{ $counts[$name] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    @error('ticket') <p class="ad-flash ad-flash--error" role="alert"><x-admin.icon name="alert" />{{ $message }}</p> @enderror

    <x-admin.card flush>
        @if ($tickets->isEmpty())
            <x-admin.state kind="empty" :title="__('admin/support.empty')" />
        @else
            <div class="ad-table-wrap">
                <table class="ad-table ad-table--cards">
                    <thead><tr>
                        <th>{{ __('admin/support.member') }}</th>
                        <th>{{ __('admin/support.category') }}</th>
                        <th>{{ __('admin/support.priority') }}</th>
                        <th>{{ __('admin/support.due') }}</th>
                        <th>{{ __('admin/support.handled_by') }}</th>
                        <th><span class="ad-sr">{{ __('admin/support.actions') }}</span></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($tickets as $ticket)
                        @php
                            $overdue = $ticket->sla_due_at !== null && $ticket->sla_due_at->isPast() && in_array($ticket->status->value, ['open', 'in_progress'], true);
                            $priority = $ticket->priority->value;
                            $done = in_array($ticket->status->value, ['resolved', 'closed'], true);
                        @endphp
                        <tr wire:key="ticket-{{ $ticket->id }}" @class(['ad-row--flagged' => $overdue])>
                            <td data-label="{{ __('admin/support.member') }}">
                                @can('member.view')
                                    <a class="ad-link" href="{{ route('admin.members', ['member' => $ticket->user_id]) }}" wire:navigate><bdi>{{ $ticket->user?->full_name ?? '—' }}</bdi></a>
                                @else
                                    <bdi>{{ $ticket->user?->full_name ?? '—' }}</bdi>
                                @endcan
                                <div class="ad-muted ad-num" style="font-size:12px">{{ $ticket->created_at?->timezone('Africa/Cairo')->format('j M H:i') }}</div>
                            </td>
                            <td data-label="{{ __('admin/support.category') }}">{{ \Illuminate\Support\Facades\Lang::has('admin/support.categories.'.$ticket->category) ? __('admin/support.categories.'.$ticket->category) : \Illuminate\Support\Str::headline($ticket->category) }}</td>
                            <td data-label="{{ __('admin/support.priority') }}">
                                <span class="ad-chip ad-chip--{{ ['urgent' => 'bad', 'high' => 'warn', 'medium' => 'info'][$priority] ?? 'plain' }}">{{ __('admin/support.priorities.'.$priority) }}</span>
                            </td>
                            <td data-label="{{ __('admin/support.due') }}" class="ad-num" @if ($overdue) style="color:var(--danger-600);font-weight:700" @endif>
                                {{ $ticket->sla_due_at?->timezone('Africa/Cairo')->format('D j M H:i') ?? '—' }}
                            </td>
                            <td data-label="{{ __('admin/support.handled_by') }}">{{ $ticket->assignedAdmin?->name ?? __('admin/support.nobody') }}</td>
                            <td class="ad-end">
                                @if ($mayAct && ! $done && $finishing !== $ticket->id)
                                    <div class="ad-row" style="justify-content:flex-end;gap:6px;flex-wrap:nowrap">
                                        @if ($ticket->assigned_admin_id !== auth('admin')->id())
                                            <button type="button" class="ad-btn ad-btn--sm ad-btn--primary" wire:click="take('{{ $ticket->id }}')" wire:loading.attr="disabled">{{ __('admin/support.take') }}</button>
                                        @endif
                                        <button type="button" class="ad-btn ad-btn--sm" wire:click="startFinish('{{ $ticket->id }}', 'resolved')">{{ __('admin/support.resolve') }}</button>
                                        <button type="button" class="ad-btn ad-btn--sm ad-btn--quiet" wire:click="startFinish('{{ $ticket->id }}', 'closed')">{{ __('admin/support.close') }}</button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                        @if ($mayAct && $finishing === $ticket->id)
                            <tr class="ad-row-detail" wire:key="ticket-finish-{{ $ticket->id }}">
                                <td colspan="6">
                                    <form wire:submit="finish">
                                        <div class="ad-field">
                                            <label class="ad-label" for="note-{{ $ticket->id }}">{{ __('admin/support.note') }}</label>
                                            <textarea id="note-{{ $ticket->id }}" class="ad-textarea" wire:model="note" rows="2" required placeholder="{{ __('admin/support.note_placeholder') }}"></textarea>
                                            @error('note') <span class="ad-error">{{ $message }}</span> @enderror
                                        </div>
                                        <div class="ad-row">
                                            <button type="submit" class="ad-btn ad-btn--primary" wire:loading.attr="disabled">{{ $finishAs === 'closed' ? __('admin/support.confirm_close') : __('admin/support.confirm_resolve') }}</button>
                                            <button type="button" class="ad-btn" wire:click="$set('finishing', null)">{{ __('admin.queue.cancel') }}</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-admin.card>

    <x-rq-pager :page="$tickets" />
</div>
