{{-- REPORTS AND DISPUTES (R01–R0x). Polls like the safety desk: a report's deadline moves while the page is open. --}}
<div wire:poll.30s.visible>
    <x-admin.page-head :title="__('admin/reports.title')" :sub="trans_choice('admin.safety.reports_open', $reports->total(), ['count' => $reports->total()]).' · '.__('admin/reports.sub')">
        @can('safety.view')
            <a class="ad-btn" href="{{ route('admin.safety') }}" wire:navigate><x-admin.icon name="alert" />{{ __('admin/shell.nav.sos') }}</a>
        @endcan
    </x-admin.page-head>

    @if (session('status'))
        <p class="ad-flash" role="status"><x-admin.icon name="check" />{{ session('status') }}</p>
    @endif

    @include('livewire.admin.partials.reports-list')
</div>
