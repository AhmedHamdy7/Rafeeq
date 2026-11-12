{{--
    AUDIT LOG (AU). Each entry: when, who, what, on which record, why, and what changed.
    A table (cards on a phone); before / after open under the row.
--}}
<div>
    <x-admin.page-head :title="__('admin.audit.title')" :sub="__('admin.audit.sub')" />

    <div class="ad-toolbar">
        <select class="ad-select" style="inline-size:auto" wire:model.live="area" aria-label="{{ __('admin.audit.area') }}">
            <option value="">{{ __('admin.audit.all_areas') }}</option>
            @foreach (\App\Livewire\Admin\AuditLog::AREAS as $name)
                <option value="{{ $name }}">{{ __('admin.audit.areas.'.$name) }}</option>
            @endforeach
        </select>
        <select class="ad-select" style="inline-size:auto" wire:model.live="adminId" aria-label="{{ __('admin.audit.by') }}">
            <option value="">{{ __('admin.audit.anyone') }}</option>
            @foreach ($staff as $person)
                <option value="{{ $person->id }}">{{ $person->name }}</option>
            @endforeach
        </select>
        <label class="ad-search" style="flex:1;min-inline-size:200px">
            <x-admin.icon name="search" />
            <input type="search" wire:model.live.debounce.400ms="subject"
                   placeholder="{{ __('admin.audit.subject') }}" aria-label="{{ __('admin.audit.subject') }}">
        </label>
    </div>

    <x-admin.card flush>
        @if ($entries->isEmpty())
            <x-admin.state kind="empty" :title="__('admin.audit.empty')" />
        @else
            <div class="ad-table-wrap">
                <table class="ad-table ad-table--cards">
                    <thead>
                    <tr>
                        <th>{{ __('admin/audit.when') }}</th>
                        <th>{{ __('admin.audit.by') }}</th>
                        <th>{{ __('admin/audit.what') }}</th>
                        <th>{{ __('admin/audit.record') }}</th>
                        <th>{{ __('admin/audit.why') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($entries as $entry)
                        @php($label = 'admin.audit.actions.'.str_replace('.', '__', $entry->action))
                        <tr wire:key="audit-{{ $entry->id }}">
                            <td data-label="{{ __('admin/audit.when') }}" class="ad-num ad-muted" style="white-space:nowrap">{{ $entry->created_at?->timezone('Africa/Cairo')->format('D j M Y, H:i:s') }}</td>
                            <td data-label="{{ __('admin.audit.by') }}">{{ $entry->admin?->name ?? '—' }}</td>
                            <td data-label="{{ __('admin/audit.what') }}">
                                {{-- An action with no wording yet still reads as itself rather than as a translation key. --}}
                                <div style="font-weight:650">{{ \Illuminate\Support\Facades\Lang::has($label) ? __($label) : $entry->action }}</div>
                                <span class="ad-code">{{ $entry->action }}</span>
                            </td>
                            <td data-label="{{ __('admin/audit.record') }}">{{ $entry->entity_type }} <span class="ad-mono ad-muted">{{ $entry->entity_id }}</span></td>
                            <td data-label="{{ __('admin/audit.why') }}" dir="auto">{{ $entry->reason ?? '—' }}</td>
                        </tr>
                        @if ($entry->old_value || $entry->new_value)
                            <tr class="ad-row-detail" wire:key="audit-diff-{{ $entry->id }}">
                                <td colspan="5">
                                    <dl class="ad-kv">
                                        <dt>{{ __('admin.audit.before') }}</dt><dd class="ad-mono" dir="ltr" style="text-align:start">{{ json_encode($entry->old_value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</dd>
                                        <dt>{{ __('admin.audit.after') }}</dt><dd class="ad-mono" dir="ltr" style="text-align:start">{{ json_encode($entry->new_value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</dd>
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

    <x-rq-pager :page="$entries" position="admin.pager.position_audit" />
</div>
