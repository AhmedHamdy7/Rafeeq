@props(['page', 'position' => 'admin.pager.position'])

{{--
    Paging controls in the dashboard's own design language.

    Hand-rolled rather than `$page->links()`, which renders Laravel's Tailwind pagination
    views — a different visual vocabulary that would need publishing and overriding to
    look like anything else on this page.

    `wire:click` on the methods Livewire's WithPagination trait provides, so turning a
    page is an AJAX re-render like every other action here: no navigation, no scroll jump,
    no full reload.

    `position` names the line under the controls, because ":total waiting" is right for a
    review queue and wrong for a list of cars on the road.
--}}
@if ($page->hasPages())
    <nav
        class="ad-pager"
        role="navigation"
        aria-label="{{ __('admin.pager.label') }}"
    >
        <button
            type="button"
            class="ad-btn ad-btn--sm"
            wire:click="previousPage"
            wire:loading.attr="disabled"
            @disabled($page->onFirstPage())
        >
            <x-admin.icon name="chevron" size="14" class="ad-pager__back" />{{ __('admin.pager.previous') }}
        </button>

        <span class="ad-muted ad-num" style="flex:1;text-align:center;font-size:12.5px">
            {{ __($position, [
                'page' => $page->currentPage(),
                'last' => $page->lastPage(),
                'total' => $page->total(),
            ]) }}
        </span>

        <button
            type="button"
            class="ad-btn ad-btn--sm"
            wire:click="nextPage"
            wire:loading.attr="disabled"
            @disabled(! $page->hasMorePages())
        >
            {{ __('admin.pager.next') }}<x-admin.icon name="chevron" size="14" class="ad-flip" />
        </button>
    </nav>
@endif
