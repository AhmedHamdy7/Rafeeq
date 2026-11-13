{{--
    The dashboard shell (RAFEEQ_Admin_Dashboard.html): sidebar · top bar · SOS bar · page.
    Everything inside it is behind auth + MFA + a fresh session.

    - The sidebar comes from App\Domains\Admin\Support\Navigation: groups, order, icons,
      permissions. Its count badges come from the view composer in AppServiceProvider.
    - Arabic / English: the globe in the top bar posts to admin.locale; `dir` follows.
    - Light / dark: set before first paint (no white flash), remembered per browser.
    - Responsive: see public/css/admin/layout.css — open sidebar ≥ 1280px, icons only
      below that, a drawer under 768px.
--}}
@php
    use App\Domains\Admin\Enums\AdminPermission;
    use App\Domains\Admin\Support\Navigation;
    use Illuminate\Support\Facades\Route;

    $admin = auth('admin')->user();
    $locale = app()->getLocale();
    $navigation = Navigation::for($admin);
    $badges = [
        'openSafetyCases' => $openSafetyCases ?? 0,
        'liveTrips' => $liveTrips ?? 0,
        'pendingDrivers' => $pendingDrivers ?? 0,
        'pendingVerifications' => $pendingVerifications ?? 0,
        'pendingReplacementCars' => $pendingReplacementCars ?? 0,
        'overdueHolds' => $overdueHolds ?? 0,
    ];
    $mayReadSafety = $admin?->can(AdminPermission::SafetyView->value) === true;
    $maySearch = $admin?->can(AdminPermission::MemberView->value) === true;
    $role = $admin?->getRoleNames()->first();
@endphp
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
    <link rel="preload" href="{{ asset($locale === 'ar' ? 'fonts/cairo-arabic-wght-normal.woff2' : 'fonts/inter-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>

    {{--
        Theme and sidebar width, applied before first paint: doing it after render means a
        dark-mode user sees a white flash on every navigation. try/catch because
        localStorage throws in a private window, and a preference is not worth a blank page.
    --}}
    <script>
        (function () {
            var root = document.documentElement;
            try {
                var saved = localStorage.getItem('rq-theme');
                var system = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                root.dataset.theme = saved || system;
                root.dataset.side = localStorage.getItem('rq-side') || 'open';
            } catch (e) {
                root.dataset.theme = 'light';
                root.dataset.side = 'open';
            }
        })();
    </script>
    @livewireStyles
</head>
<body class="ad-body">
<div
    class="ad-app"
    x-data="{
        drawer: false,
        collapsed: document.documentElement.dataset.side === 'collapsed',
        theme: document.documentElement.dataset.theme,
        toggleSide() {
            this.collapsed = ! this.collapsed;
            document.documentElement.dataset.side = this.collapsed ? 'collapsed' : 'open';
            try { localStorage.setItem('rq-side', this.collapsed ? 'collapsed' : 'open'); } catch (e) {}
        },
        toggleTheme() {
            this.theme = this.theme === 'dark' ? 'light' : 'dark';
            document.documentElement.dataset.theme = this.theme;
            try { localStorage.setItem('rq-theme', this.theme); } catch (e) {}
        },
    }"
    x-bind:class="{ 'ad-drawer-open': drawer }"
    x-on:keydown.escape.window="drawer = false"
>
    {{-- Tablet and phone: the open drawer dims the page; a tap outside closes it. --}}
    <div class="ad-scrim" x-show="drawer" x-on:click="drawer = false" x-cloak></div>

    <aside class="ad-side" id="ad-side" aria-label="{{ __('admin/shell.nav.label') }}">
        <div class="ad-brand">
            <span class="ad-brand__mark" aria-hidden="true">R</span>
            <span class="ad-brand__text">
                <span class="ad-brand__name">{{ __('admin/shell.brand') }}</span>
                <span class="ad-brand__sub">{{ __('admin/shell.brand_sub') }}</span>
            </span>
            <button type="button" class="ad-icon-btn ad-brand__toggle" x-on:click="toggleSide()"
                    x-bind:aria-expanded="collapsed ? 'false' : 'true'" aria-controls="ad-side">
                <x-admin.icon name="panel" />
                <span class="ad-sr">{{ __('admin/shell.nav.collapse') }}</span>
            </button>
        </div>

        @foreach ($navigation as $group)
            <div class="ad-navg">{{ __('admin/shell.groups.'.$group['key']) }}</div>
            <nav aria-label="{{ __('admin/shell.groups.'.$group['key']) }}">
                @foreach ($group['items'] as $item)
                    @php($count = $item['badge'] ? ($badges[$item['badge']] ?? 0) : 0)
                    <a
                        href="{{ route($item['route']) }}"
                        wire:navigate
                        class="ad-nav"
                        title="{{ __('admin/shell.nav.'.$item['key']) }}"
                        x-on:click="drawer = false"
                        @if (request()->routeIs($item['route'])) aria-current="page" @endif
                    >
                        <x-admin.icon :name="$item['icon']" />
                        <span class="ad-nav__label">{{ __('admin/shell.nav.'.$item['key']) }}</span>
                        @if ($count > 0)
                            <span class="ad-badge ad-badge--{{ $item['tone'] }}">{{ $count }}</span>
                        @endif
                    </a>
                @endforeach
            </nav>
        @endforeach
    </aside>

    <div class="ad-main">
        <header class="ad-top">
            <button type="button" class="ad-icon-btn ad-menu-btn" x-on:click="drawer = true" aria-controls="ad-side">
                <x-admin.icon name="menu" size="18" />
                <span class="ad-sr">{{ __('admin/shell.nav.open') }}</span>
            </button>

            @if ($maySearch && Route::has('admin.members'))
                {{-- Finds a person by phone, name or reference — the users directory does the search. --}}
                <form class="ad-search" method="GET" action="{{ route('admin.members') }}" role="search">
                    <x-admin.icon name="search" />
                    <input type="search" name="q" value="{{ request()->routeIs('admin.members') ? request('q') : '' }}"
                           placeholder="{{ __('admin/shell.search.placeholder') }}"
                           aria-label="{{ __('admin/shell.search.label') }}"
                           x-on:keydown.window.slash="if (! ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) { $event.preventDefault(); $el.focus(); }">
                    <kbd aria-hidden="true">/</kbd>
                </form>
                <a href="{{ route('admin.members') }}" wire:navigate class="ad-icon-btn ad-search-icon">
                    <x-admin.icon name="search" size="18" />
                    <span class="ad-sr">{{ __('admin/shell.search.label') }}</span>
                </a>
            @endif

            <span class="ad-top__spacer"></span>

            @if ($mayReadSafety && ($openSafetyCases ?? 0) > 0 && Route::has('admin.safety'))
                <a href="{{ route('admin.safety') }}" wire:navigate class="ad-sosind">
                    <x-admin.icon name="alert" size="14" />
                    <span class="ad-sosind__label">{{ __('admin/shell.sos.indicator') }} ·</span>
                    <span class="ad-num">{{ $openSafetyCases }}</span>
                </a>
            @endif

            {{-- N03 · Team alerts: what is waiting past its promise, only what this person may open. --}}
            @php($alertTotal = collect($teamAlerts ?? [])->sum('count'))
            <div class="ad-menu-anchor" x-data="{ open: false }" x-on:click.outside="open = false">
                <button type="button" class="ad-icon-btn" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-haspopup="menu">
                    <x-admin.icon name="bell" size="18" />
                    @if ($alertTotal > 0)<span class="ad-icon-btn__dot" aria-hidden="true"></span>@endif
                    <span class="ad-sr">{{ trans_choice('admin/shell.alerts.label', $alertTotal, ['count' => $alertTotal]) }}</span>
                </button>
                <div class="ad-pop" role="menu" x-show="open" x-cloak style="inline-size:300px">
                    <div class="ad-pop__head">{{ __('admin/shell.alerts.title') }}</div>
                    @forelse ($teamAlerts ?? [] as $alert)
                        <a class="ad-pop__item" role="menuitem" href="{{ route($alert['route'], $alert['params']) }}" wire:navigate>
                            <span class="ad-badge ad-badge--{{ $alert['tone'] }}">{{ $alert['count'] }}</span>
                            <span>{{ trans_choice('admin/shell.alerts.'.$alert['key'], $alert['count'], ['count' => $alert['count'], 'hours' => \App\Domains\Admin\Support\TeamAlerts::LATE_HOURS]) }}</span>
                        </a>
                    @empty
                        <p class="ad-hint" style="margin:0;padding:8px 10px">{{ __('admin/shell.alerts.none') }}</p>
                    @endforelse
                </div>
            </div>

            {{-- Language. A form, because switching changes the session (never a GET). --}}
            <div class="ad-menu-anchor" x-data="{ open: false }" x-on:click.outside="open = false">
                <button type="button" class="ad-icon-btn" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-haspopup="menu">
                    <x-admin.icon name="globe" size="18" />
                    <span class="ad-sr">{{ __('admin/shell.language') }}</span>
                </button>
                <div class="ad-pop" role="menu" x-show="open" x-cloak style="inline-size:180px">
                    @foreach (['ar' => 'العربية', 'en' => 'English'] as $code => $name)
                        <form method="POST" action="{{ route('admin.locale', $code) }}">
                            @csrf
                            <button type="submit" class="ad-pop__item" role="menuitemradio" aria-checked="{{ $locale === $code ? 'true' : 'false' }}" lang="{{ $code }}">
                                {{ $name }}
                                @if ($locale === $code)
                                    <x-admin.icon name="check" style="margin-inline-start:auto" />
                                @endif
                            </button>
                        </form>
                    @endforeach
                </div>
            </div>

            <button type="button" class="ad-icon-btn" x-on:click="toggleTheme()">
                <span x-show="theme !== 'dark'"><x-admin.icon name="moon" size="18" /></span>
                <span x-show="theme === 'dark'" x-cloak><x-admin.icon name="sun" size="18" /></span>
                <span class="ad-sr">{{ __('admin/shell.theme') }}</span>
            </button>

            <div class="ad-menu-anchor" x-data="{ open: false }" x-on:click.outside="open = false">
                <button type="button" class="ad-user" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-haspopup="menu">
                    <span class="ad-avatar" aria-hidden="true">{{ mb_substr((string) $admin?->name, 0, 1) }}</span>
                    <span class="ad-user__text">
                        <span class="ad-user__name">{{ $admin?->name }}</span>
                        @if ($role)
                            <span class="ad-chip ad-chip--brand ad-chip--plain" style="block-size:18px;font-size:11px">{{ __('admin/shell.roles.'.$role) }}</span>
                        @endif
                    </span>
                    <x-admin.icon name="chevron-down" size="14" class="ad-subtle" />
                </button>
                <div class="ad-pop" role="menu" x-show="open" x-cloak>
                    <div class="ad-pop__head">{{ __('admin/shell.signed_in_as') }}</div>
                    <div style="padding:2px 10px 8px">
                        <div style="font-weight:650">{{ $admin?->name }}</div>
                        <div class="ad-muted" style="font-size:12px;overflow-wrap:anywhere">{{ $admin?->email }}</div>
                    </div>
                    <hr class="ad-pop__sep">
                    <a href="{{ route('admin.account') }}" wire:navigate class="ad-pop__item" role="menuitem">
                        <x-admin.icon name="settings" />{{ __('admin/account.title') }}
                    </a>
                    <form method="POST" action="{{ route('admin.logout') }}">
                        @csrf
                        <button type="submit" class="ad-pop__item" role="menuitem">
                            <x-admin.icon name="logout" class="ad-flip" />
                            {{ __('admin/shell.sign_out') }}
                        </button>
                    </form>
                </div>
            </div>
        </header>

        @if ($unacknowledgedAlert ?? null)
            {{-- Same alert, same wording, on every page: see the composer in AppServiceProvider. --}}
            <div class="ad-sosbar" role="alert" wire:key="sos-banner-{{ $unacknowledgedAlert->id }}">
                <x-admin.icon name="alert" />
                <span class="ad-sosbar__text">
                    <strong>{{ __($unacknowledgedAlert->is_discreet ? 'admin.safety.banner_silent' : 'admin.safety.banner', ['ago' => $unacknowledgedAlert->created_at->diffForHumans()]) }}</strong>
                </span>
                <a class="ad-btn ad-btn--sm" href="{{ route('admin.safety') }}" wire:navigate>{{ __('admin.safety.open') }}</a>
            </div>
        @endif

        <main class="ad-content" id="ad-content">
            @if (session('status'))
                {{-- `wire:key` so a flash from one action is not reused by the next render. --}}
                <p class="ad-flash" role="status" wire:key="flash-{{ md5(session('status')) }}">
                    <x-admin.icon name="check" />
                    {{ session('status') }}
                </p>
            @endif

            {{ $slot }}
        </main>
    </div>

    {{--
        S07 · "Still there?" A minute before the idle sign-out. Counts the staff member's
        own activity (keys, clicks, touch, scroll), not the page's: a board that polls every
        few seconds must not keep an unattended browser signed in. At zero it signs out with
        reason=idle, and the sign-in page says why (S08). The server keeps its own limit
        (EnsureAdminSessionIsFresh) whatever this script does.
    --}}
    <div
        x-data="{
            limit: {{ (int) config('rafeeq.admin.session_idle_minutes') * 60 }},
            idle: 0,
            warn: false,
            get left() { return Math.max(0, this.limit - this.idle) },
            get clock() { return Math.floor(this.left / 60) + ':' + String(this.left % 60).padStart(2, '0') },
            init() {
                const seen = () => { if (! this.warn) this.idle = 0 };
                ['keydown', 'pointerdown', 'scroll', 'touchstart'].forEach(e => window.addEventListener(e, seen, { passive: true, capture: true }));
                setInterval(() => {
                    this.idle++;
                    this.warn = this.left <= 60;
                    if (this.left === 0) this.$refs.idleOut.submit();
                }, 1000);
            },
            stay() {
                fetch('{{ route('admin.ping') }}', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                this.idle = 0;
                this.warn = false;
            },
        }"
    >
        <form x-ref="idleOut" method="POST" action="{{ route('admin.logout') }}" hidden>
            @csrf
            <input type="hidden" name="reason" value="idle">
        </form>
        <div class="ad-dim" x-show="warn" x-cloak>
            <div class="ad-modal" role="alertdialog" aria-modal="true" aria-labelledby="idle-title" aria-describedby="idle-text">
                <h2 class="ad-modal__title" id="idle-title">{{ __('admin/auth.idle.title') }}</h2>
                <p class="ad-muted" id="idle-text" style="margin:0">
                    {!! __('admin/auth.idle.text', ['clock' => '<strong class="ad-num" x-text="clock"></strong>', 'minutes' => (int) config('rafeeq.admin.session_idle_minutes') - 1]) !!}
                </p>
                <div class="ad-modal__actions">
                    <button type="button" class="ad-btn" x-on:click="$refs.idleOut.submit()">{{ __('admin/shell.sign_out') }}</button>
                    <button type="button" class="ad-btn ad-btn--primary" x-on:click="stay()">{{ __('admin/auth.idle.stay') }}</button>
                </div>
            </div>
        </div>
    </div>
</div>
@livewireScripts
</body>
</html>
