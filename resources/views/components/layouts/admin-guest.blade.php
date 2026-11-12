{{--
    The shell for pages a signed-OUT admin sees (screens S01–S08). Separate from the
    dashboard layout because it must not render the sidebar, the current admin's name, or
    the queue counts — all three assume somebody is authenticated, and two of them would leak.

    Brand block · one card · a footnote. Language and theme switches in the corner, so the
    sign-in page reads in Arabic for staff who chose it.
--}}
@php($locale = app()->getLocale())
<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', $locale) }}"
    dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}"
    data-theme="light"
>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- 🔒 Staff pages must never be indexed. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ __('admin/shell.brand') }}</title>
    <link rel="stylesheet" href="{{ asset('css/admin/tokens.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin/base.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin/layout.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin/components.css') }}">

    {{-- Before first paint, so a dark-mode user never sees a white flash. --}}
    <script>
        (function () {
            try {
                var saved = localStorage.getItem('rq-theme');
                var system = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                document.documentElement.dataset.theme = saved || system;
            } catch (e) {
                document.documentElement.dataset.theme = 'light';
            }
        })();
    </script>
    @livewireStyles
</head>
<body class="ad-body">
<div class="ad-auth">
    <div class="ad-auth__corner">
        @foreach (['ar' => 'العربية', 'en' => 'English'] as $code => $name)
            @if ($code !== $locale)
                <form method="POST" action="{{ route('admin.locale', $code) }}">
                    @csrf
                    <button type="submit" class="ad-btn ad-btn--sm ad-btn--quiet" lang="{{ $code }}">
                        <x-admin.icon name="globe" size="14" />{{ $name }}
                    </button>
                </form>
            @endif
        @endforeach
        <button
            type="button"
            class="ad-icon-btn"
            x-data="{ theme: document.documentElement.dataset.theme }"
            x-on:click="theme = theme === 'dark' ? 'light' : 'dark'; document.documentElement.dataset.theme = theme; try { localStorage.setItem('rq-theme', theme) } catch (e) {}"
        >
            <span x-show="theme !== 'dark'"><x-admin.icon name="moon" size="18" /></span>
            <span x-show="theme === 'dark'" x-cloak><x-admin.icon name="sun" size="18" /></span>
            <span class="ad-sr">{{ __('admin/shell.theme') }}</span>
        </button>
    </div>

    <div class="ad-auth__inner">
        <div class="ad-auth__brand">
            <span class="ad-brand__mark" aria-hidden="true">R</span>
            <span class="ad-brand__text">
                <span class="ad-brand__name">{{ __('admin/shell.brand') }}</span>
                <span class="ad-brand__sub">{{ __('admin/auth.brand_sub') }}</span>
            </span>
        </div>

        <main class="ad-card ad-auth__card">
            {{ $slot }}
        </main>

        <p class="ad-auth__foot">{{ $foot ?? __('admin/auth.foot') }}</p>
    </div>
</div>
@livewireScripts
</body>
</html>
