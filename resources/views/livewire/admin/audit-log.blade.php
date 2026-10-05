{{--
    AUDIT LOG. Each entry: when, who, what, on which record, why, and what changed.
--}}
<div>
    <h1 class="rq-h1">{{ __('admin.audit.title') }}</h1>
    <p class="rq-sub">{{ __('admin.audit.sub') }}</p>

    <div class="rq-filters">
        <select class="rq-field" style="width:auto" wire:model.live="area" aria-label="{{ __('admin.audit.area') }}">
            <option value="">{{ __('admin.audit.all_areas') }}</option>
            @foreach (\App\Livewire\Admin\AuditLog::AREAS as $name)
                <option value="{{ $name }}">{{ __('admin.audit.areas.'.$name) }}</option>
            @endforeach
        </select>
        <select class="rq-field" style="width:auto" wire:model.live="adminId" aria-label="{{ __('admin.audit.by') }}">
            <option value="">{{ __('admin.audit.anyone') }}</option>
            @foreach ($staff as $person)
                <option value="{{ $person->id }}">{{ $person->name }}</option>
            @endforeach
        </select>
        <input type="search" class="rq-field" style="flex:1;min-width:200px" wire:model.live.debounce.400ms="subject"
               placeholder="{{ __('admin.audit.subject') }}" aria-label="{{ __('admin.audit.subject') }}">
    </div>

    @forelse ($entries as $entry)
        <article class="rq-card" wire:key="audit-{{ $entry->id }}">
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <div style="flex:1;min-width:200px">
                    @php($label = 'admin.audit.actions.'.str_replace('.', '__', $entry->action))
                    {{-- An action with no wording yet still reads as itself rather than as a translation key. --}}
                    <div style="font-weight:600">{{ \Illuminate\Support\Facades\Lang::has($label) ? __($label) : $entry->action }}</div>
                    <div class="rq-note">
                        {{ $entry->admin?->name ?? '—' }}
                        &middot; {{ $entry->created_at?->timezone('Africa/Cairo')->format('D j M Y, H:i:s') }}
                        &middot; {{ $entry->entity_type }} <span class="rq-mono">{{ $entry->entity_id }}</span>
                    </div>
                </div>
                <span class="rq-pill rq-pill--count rq-mono">{{ $entry->action }}</span>
            </div>
            @if ($entry->reason)
                <p style="margin:10px 0 0;font-size:13.5px" dir="auto">{{ $entry->reason }}</p>
            @endif
            @if ($entry->old_value || $entry->new_value)
                <dl class="rq-facts">
                    <div><dt>{{ __('admin.audit.before') }}</dt><dd class="rq-mono">{{ json_encode($entry->old_value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</dd></div>
                    <div><dt>{{ __('admin.audit.after') }}</dt><dd class="rq-mono">{{ json_encode($entry->new_value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</dd></div>
                </dl>
            @endif
        </article>
    @empty
        <p class="rq-empty">{{ __('admin.audit.empty') }}</p>
    @endforelse

    <x-rq-pager :page="$entries" position="admin.pager.position_audit" />
</div>
