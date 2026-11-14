{{--
    The top-bar search. Results as you type, grouped; ↑ ↓ to move, Enter to open, Esc to close,
    "/" from anywhere to focus. Enter with nothing picked searches Users, as before.
--}}
<div class="ad-search-wrap"
     x-data="{
        open: false, active: -1,
        links() { return [...$el.querySelectorAll('[data-search-link]')] },
        move(step) { const l = this.links(); if (! l.length) return; this.active = (this.active + step + l.length) % l.length; l[this.active].scrollIntoView({ block: 'nearest' }) },
        go() { const l = this.links(); const target = this.active >= 0 ? l[this.active] : (@js($membersUrl) ? null : l[0]); if (target) { target.click() } else if (@js($membersUrl)) { Livewire.navigate(@js($membersUrl)) } this.open = false },
     }"
     x-on:click.outside="open = false"
     x-on:keydown.escape="open = false; $refs.input.blur()">
    <form class="ad-search" role="search" x-on:submit.prevent="go()">
        <x-admin.icon name="search" />
        <input type="search" x-ref="input" wire:model.live.debounce.250ms="q" autocomplete="off"
               placeholder="{{ __('admin/shell.search.placeholder') }}"
               aria-label="{{ __('admin/shell.search.label') }}"
               aria-controls="ad-search-results" x-bind:aria-expanded="open"
               x-on:focus="open = true" x-on:input="open = true; active = -1"
               x-on:keydown.down.prevent="open = true; move(1)" x-on:keydown.up.prevent="move(-1)"
               x-on:keydown.window.slash="if (! ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) { $event.preventDefault(); $el.focus(); }">
        <span wire:loading.delay wire:target="q" class="ad-muted" style="font-size:12px">…</span>
        <kbd aria-hidden="true">/</kbd>
    </form>

    @if (mb_strlen(trim($q)) >= 2)
        <div id="ad-search-results" class="ad-pop ad-search-results" role="listbox" x-show="open" x-cloak>
            @forelse ($groups as $group)
                <div class="ad-pop__head">{{ __('admin/shell.search.groups.'.$group['group']) }}</div>
                @foreach ($group['items'] as $item)
                    <a class="ad-pop__item ad-search-results__item" href="{{ $item['url'] }}" wire:navigate data-search-link role="option"
                       x-bind:aria-selected="active === links().indexOf($el)" x-on:mouseenter="active = links().indexOf($el)">
                        <span class="ad-search-results__label"><bdi>{{ $item['label'] }}</bdi></span>
                        @if ($item['meta'])<span class="ad-search-results__meta" dir="auto">{{ $item['meta'] }}</span>@endif
                    </a>
                @endforeach
            @empty
                <p class="ad-hint" style="margin:8px 12px">{{ __('admin/shell.search.none') }}</p>
            @endforelse
            @if ($membersUrl)
                <div class="ad-pop__sep"></div>
                <a class="ad-pop__item ad-search-results__all" href="{{ $membersUrl }}" wire:navigate data-search-link>
                    <x-admin.icon name="users" size="14" />{{ __('admin/shell.search.all_members', ['q' => trim($q)]) }}
                </a>
            @endif
            <p class="ad-hint ad-search-results__keys" aria-hidden="true">{{ __('admin/shell.search.keys') }}</p>
        </div>
    @endif
</div>
