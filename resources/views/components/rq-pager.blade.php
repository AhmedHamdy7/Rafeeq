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
        style="display:flex;align-items:center;gap:10px;margin-top:18px;flex-wrap:wrap"
        role="navigation"
        aria-label="{{ __('admin.pager.label') }}"
    >
        <button
            type="button"
            class="rq-btn rq-btn--quiet"
            wire:click="previousPage"
            wire:loading.attr="disabled"
            @disabled($page->onFirstPage())
        >
            {{ __('admin.pager.previous') }}
        </button>

        <span class="rq-note" style="flex:1;text-align:center">
            {{ __($position, [
                'page' => $page->currentPage(),
                'last' => $page->lastPage(),
                'total' => $page->total(),
            ]) }}
        </span>

        <button
            type="button"
            class="rq-btn rq-btn--quiet"
            wire:click="nextPage"
            wire:loading.attr="disabled"
            @disabled(! $page->hasMorePages())
        >
            {{ __('admin.pager.next') }}
        </button>
    </nav>
@endif
