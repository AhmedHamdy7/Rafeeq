{{-- The dashboard shell. Everything inside it is behind auth + MFA + a fresh session. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['ar'], true) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('admin.title') }}</title>
    @livewireStyles
</head>
<body style="margin:0;background:#faf9f5;font-family:system-ui,-apple-system,sans-serif;color:#1a1612">
    <header style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 22px;background:#fff;border-bottom:1px solid #e8e4dc">
        <strong style="font-size:15px">{{ __('admin.title') }}</strong>
        <nav style="display:flex;gap:14px;font-size:13.5px">
            {{--
                Each link is rendered only if the signed-in admin may use the page.
                A hidden link is NOT the permission check — the component re-checks —
                but showing somebody a door that 403s is its own small cruelty.
            --}}
            @can(\App\Domains\Admin\Enums\AdminPermission::VerificationView->value)
                <a href="{{ route('admin.verifications') }}" wire:navigate>{{ __('admin.nav.verifications') }}</a>
            @endcan
            @can(\App\Domains\Admin\Enums\AdminPermission::DriverView->value)
                <a href="{{ route('admin.drivers') }}" wire:navigate>{{ __('admin.nav.drivers') }}</a>
            @endcan
        </nav>
        <form method="POST" action="{{ route('admin.logout') }}" style="margin:0">
            @csrf
            <button type="submit" style="border:1px solid #e8e4dc;background:#faf9f5;border-radius:999px;padding:6px 14px;font-size:13px;cursor:pointer">
                {{ __('admin.nav.sign_out') }}
            </button>
        </form>
    </header>

    <main style="max-width:940px;margin:0 auto;padding:22px">
        @if (session('status'))
            <p style="margin:0 0 16px;padding:11px 14px;border-radius:10px;background:#e7f5ee;color:#1d6b4a;font-size:13.5px">{{ session('status') }}</p>
        @endif
        {{ $slot }}
    </main>
    @livewireScripts
</body>
</html>
