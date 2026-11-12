{{--
    The top of every page: title, a line under it, and the page's actions on the end side.
    <x-admin.page-head :title="__('…')" :sub="__('…')"> <button …>…</button> </x-admin.page-head>
--}}
@props(['title', 'sub' => null])
<div {{ $attributes->merge(['class' => 'ad-page-head']) }}>
    <div style="min-inline-size:0">
        <h1 class="ad-page-title">{{ $title }}</h1>
        @if ($sub)
            <p class="ad-page-sub">{{ $sub }}</p>
        @endif
    </div>
    @if (trim($slot) !== '')
        <div class="ad-row">{{ $slot }}</div>
    @endif
</div>
