{{--
    The dashboard shell. Everything inside it is behind auth + MFA + a fresh session.

    Layout follows `rafiq-super-admin-standalone.html`: a desk, a rounded surface card,
    a 224px rail of sections, and the content beside it. On a phone the rail becomes a
    drawer — see the responsive block in public/css/admin.css.

    Navigation uses `wire:navigate`, so moving between pages swaps the content over AJAX
    and keeps the rail, the scroll position and the theme. Nothing here does a full page
    load, and neither does any action: Livewire posts and re-renders in place.
--}}
@php
    use App\Domains\Admin\Enums\AdminPermission;

    $admin = auth('admin')->user();
@endphp
<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}"
    {{-- Set before paint by the script below; the attribute here is the no-JS default. --}}
    data-theme="light"
>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- 🔒 Staff pages must never be indexed. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('admin.title') }}</title>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">

    {{--
        Applied before the first paint, in the head, on purpose: doing it after render
        means a dark-mode user sees a white flash on every navigation. Remembered per
        browser, falling back to the operating system's own setting.

        Wrapped in try/catch because localStorage throws in a private window with site
        data blocked, and a theme preference is not worth a blank page.
    --}}
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
<body>
<div class="rq-desk" x-data="{ rail: false }">
    <div class="rq-shell">

        {{-- The scrim only exists while the drawer is open, and closes it on tap. --}}
        <div class="rq-scrim" x-show="rail" x-on:click="rail = false" x-cloak></div>

        <aside class="rq-rail" x-bind:data-open="rail ? 'true' : 'false'">
            <div class="rq-brand">
                <span class="rq-brand__mark" aria-hidden="true">ر</span>
                <span class="rq-brand__name">{{ __('admin.title') }}</span>
                <button
                    type="button"
                    class="rq-btn rq-btn--icon rq-rail__close"
                    x-on:click="rail = false"
                    style="margin-inline-start:auto"
                >
                    <span aria-hidden="true">&times;</span>
                    <span class="rq-sr">{{ __('admin.nav.close') }}</span>
                </button>
            </div>

            <div class="rq-rail__section">{{ __('admin.nav.operations') }}</div>
            <nav style="display:flex;flex-direction:column;gap:3px">
                {{--
                    Each link is rendered only if this admin may use the page. A hidden
                    link is NOT the permission check — every component re-checks — but
                    showing somebody a door that 403s is its own small cruelty.
                --}}
                @can(AdminPermission::SafetyView->value)
                    <a
                        href="{{ route('admin.safety') }}"
                        wire:navigate
                        class="rq-nav"
                        @if (request()->routeIs('admin.safety')) aria-current="page" @endif
                    >
                        <span class="rq-nav__tile" aria-hidden="true">⚠</span>
                        <span class="rq-nav__label">{{ __('admin.nav.safety') }}</span>
                        @if ($openSafetyCases ?? 0)
                            <span class="rq-pill rq-pill--bad">{{ $openSafetyCases }}</span>
                        @endif
                    </a>
                @endcan

                @can(AdminPermission::TripView->value)
                    <a
                        href="{{ route('admin.trips') }}"
                        wire:navigate
                        class="rq-nav"
                        @if (request()->routeIs('admin.trips')) aria-current="page" @endif
                    >
                        <span class="rq-nav__tile" aria-hidden="true">⛟</span>
                        <span class="rq-nav__label">{{ __('admin.nav.trips') }}</span>
                        @if ($liveTrips ?? 0)
                            <span class="rq-pill rq-pill--ok">{{ $liveTrips }}</span>
                        @endif
                    </a>
                @endcan

                @can(AdminPermission::VerificationView->value)
                    <a
                        href="{{ route('admin.verifications') }}"
                        wire:navigate
                        class="rq-nav"
                        @if (request()->routeIs('admin.verifications')) aria-current="page" @endif
                    >
                        <span class="rq-nav__tile" aria-hidden="true">✓</span>
                        <span class="rq-nav__label">{{ __('admin.nav.verifications') }}</span>
                        @if ($pendingVerifications ?? 0)
                            <span class="rq-pill rq-pill--warn">{{ $pendingVerifications }}</span>
                        @endif
                    </a>
                @endcan

                @can(AdminPermission::DriverView->value)
                    <a
                        href="{{ route('admin.drivers') }}"
                        wire:navigate
                        class="rq-nav"
                        @if (request()->routeIs('admin.drivers')) aria-current="page" @endif
                    >
                        <span class="rq-nav__tile" aria-hidden="true">⌘</span>
                        <span class="rq-nav__label">{{ __('admin.nav.drivers') }}</span>
                        @if ($pendingDrivers ?? 0)
                            <span class="rq-pill rq-pill--count">{{ $pendingDrivers }}</span>
                        @endif
                    </a>
                @endcan
            </nav>

            <div style="flex:1"></div>

            <div class="rq-rail__section">{{ __('admin.nav.signed_in_as') }}</div>
            <div style="padding:0 9px 10px;font-size:13px">
                <div style="font-weight:600">{{ $admin?->name }}</div>
                <div class="rq-note">{{ $admin?->getRoleNames()->first() }}</div>
            </div>

            <form method="POST" action="{{ route('admin.logout') }}" style="margin:0;padding:0 9px">
                @csrf
                <button type="submit" class="rq-btn rq-btn--quiet" style="width:100%">
                    {{ __('admin.nav.sign_out') }}
                </button>
            </form>
        </aside>

        <div class="rq-main">
            <header class="rq-topbar">
                <button
                    type="button"
                    class="rq-btn rq-btn--icon rq-rail__open"
                    x-on:click="rail = true"
                >
                    <span aria-hidden="true">☰</span>
                    <span class="rq-sr">{{ __('admin.nav.menu') }}</span>
                </button>

                <span class="rq-topbar__title">{{ $title ?? __('admin.title') }}</span>

                {{--
                    Theme toggle. Writes the choice and flips the attribute in the same
                    click, so nothing re-renders and nothing round-trips to the server —
                    the whole page is already themed from CSS variables.
                --}}
                <button
                    type="button"
                    class="rq-btn rq-btn--icon"
                    x-data="{
                        theme: document.documentElement.dataset.theme,
                        flip() {
                            this.theme = this.theme === 'dark' ? 'light' : 'dark';
                            document.documentElement.dataset.theme = this.theme;
                            try { localStorage.setItem('rq-theme', this.theme); } catch (e) {}
                        },
                    }"
                    x-on:click="flip()"
                >
                    <span x-text="theme === 'dark' ? '☀' : '☾'" aria-hidden="true"></span>
                    <span class="rq-sr">{{ __('admin.nav.theme') }}</span>
                </button>
            </header>

            <main class="rq-content">
                @if ($unacknowledgedAlert ?? null)
                    {{-- Same alert, same wording, on every page: see the composer in AppServiceProvider. --}}
                    <div class="rq-banner" role="alert" wire:key="sos-banner-{{ $unacknowledgedAlert->id }}">
                        <span style="flex:1">
                            {{ __($unacknowledgedAlert->is_discreet ? 'admin.safety.banner_silent' : 'admin.safety.banner', ['ago' => $unacknowledgedAlert->created_at->diffForHumans()]) }}
                        </span>
                        <a class="rq-btn rq-btn--danger" href="{{ route('admin.safety') }}" wire:navigate>{{ __('admin.safety.open') }}</a>
                    </div>
                @endif

                @if (session('status'))
                    {{-- `wire:key` so a flash from one action is not reused by the next render. --}}
                    <p class="rq-flash" wire:key="flash-{{ md5(session('status')) }}">{{ session('status') }}</p>
                @endif

                {{ $slot }}
            </main>
        </div>
    </div>
</div>
@livewireScripts
</body>
</html>
