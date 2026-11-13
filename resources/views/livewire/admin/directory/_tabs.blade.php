{{-- Status tabs and the table tools shared by the directory pages. Needs $tabs, $status, $tableKey, $savedViews, $mayExport. --}}
<div class="ad-tabs" role="tablist">
    <button type="button" role="tab" class="ad-tab" aria-selected="{{ $status === 'all' ? 'true' : 'false' }}" wire:click="pick('all')">{{ __('admin/directory.all') }}</button>
    @foreach ($tabs as $name)
        <button type="button" role="tab" class="ad-tab" aria-selected="{{ $status === $name ? 'true' : 'false' }}" wire:click="pick('{{ $name }}')">{{ __('admin/directory.statuses.'.$name) }}</button>
    @endforeach
</div>

<div class="ad-row" data-ad-tools="{{ $tableKey }}" style="gap:6px;margin-block-end:10px;justify-content:flex-end">
    {{-- Saved views: this staff member's named filter sets for this page. --}}
    <div class="ad-menu-anchor" x-data="{ open: false }" x-on:click.outside="open = false">
        <button type="button" class="ad-btn ad-btn--sm" x-on:click="open = ! open" x-bind:aria-expanded="open"><x-admin.icon name="bookmark" size="14" />{{ __('admin/tables.views') }}</button>
        <div class="ad-pop" x-show="open" x-cloak style="inline-size:260px">
            <div class="ad-pop__head">{{ __('admin/tables.views') }}</div>
            @forelse ($savedViews as $viewName => $viewFilters)
                <div class="ad-row" style="gap:4px;flex-wrap:nowrap" wire:key="view-{{ md5($viewName) }}">
                    <button type="button" class="ad-pop__item" style="flex:1" x-on:click="open = false" wire:click="applyView(@js($viewName))"><bdi>{{ $viewName }}</bdi></button>
                    <button type="button" class="ad-icon-btn" wire:click="deleteView(@js($viewName))" aria-label="{{ __('admin/tables.delete_view', ['name' => $viewName]) }}"><x-admin.icon name="x" size="14" /></button>
                </div>
            @empty
                <p class="ad-hint" style="margin:4px 10px">{{ __('admin/tables.no_views') }}</p>
            @endforelse
            <form wire:submit="saveView" class="ad-row" style="gap:6px;padding:8px 10px;flex-wrap:nowrap">
                <input class="ad-input" style="flex:1" wire:model="viewName" maxlength="40" placeholder="{{ __('admin/tables.view_name') }}" aria-label="{{ __('admin/tables.view_name') }}">
                <button type="submit" class="ad-btn ad-btn--sm ad-btn--primary">{{ __('admin/tables.save_view') }}</button>
            </form>
            @error('viewName') <p class="ad-error" style="margin:0 10px 8px">{{ $message }}</p> @enderror
        </div>
    </div>

    {{-- Column picker: remembered in this browser only. --}}
    <div class="ad-menu-anchor" wire:ignore
         x-data="{
            open: false, key: @js($tableKey), hidden: [], cols: [],
            init() { try { this.hidden = JSON.parse(localStorage.getItem('ad-cols-' + this.key) || '[]') } catch (e) { this.hidden = [] } this.apply() },
            load() { const t = this.$root.parentElement.parentElement.querySelector('table.ad-table'); this.cols = t ? [...t.querySelectorAll('thead th')].map((th, i) => ({ i: i + 1, label: th.textContent.trim() })) : [] },
            toggle(i) { this.hidden = this.hidden.includes(i) ? this.hidden.filter(x => x !== i) : (this.hidden.length + 1 < this.cols.length ? [...this.hidden, i] : this.hidden); this.apply() },
            apply() {
                let el = document.getElementById('ad-cols-' + this.key);
                if (! el) { el = document.createElement('style'); el.id = 'ad-cols-' + this.key; document.head.appendChild(el) }
                el.textContent = this.hidden.map(i => `div:has(> [data-ad-tools='${this.key}']) table.ad-table tr > :nth-child(${i}) { display: none }`).join('\n');
                try { localStorage.setItem('ad-cols-' + this.key, JSON.stringify(this.hidden)) } catch (e) {}
            },
         }" x-on:click.outside="open = false">
        <button type="button" class="ad-btn ad-btn--sm" x-on:click="load(); open = ! open" x-bind:aria-expanded="open"><x-admin.icon name="columns" size="14" />{{ __('admin/tables.columns') }}</button>
        <div class="ad-pop" x-show="open" x-cloak style="inline-size:220px">
            <div class="ad-pop__head">{{ __('admin/tables.columns') }}</div>
            <template x-for="col in cols" :key="col.i">
                <label class="ad-pop__item" style="cursor:pointer">
                    <input type="checkbox" :checked="! hidden.includes(col.i)" x-on:change="toggle(col.i)">
                    <span x-text="col.label"></span>
                </label>
            </template>
            <p class="ad-hint" style="margin:4px 10px 8px">{{ __('admin/tables.columns_hint') }}</p>
        </div>
    </div>

    @if ($mayExport)
        <button type="button" class="ad-btn ad-btn--sm" wire:click="export" wire:loading.attr="disabled" title="{{ __('admin/tables.export_hint') }}"><x-admin.icon name="download" size="14" />{{ __('admin/tables.export_csv') }}</button>
    @endif
</div>
