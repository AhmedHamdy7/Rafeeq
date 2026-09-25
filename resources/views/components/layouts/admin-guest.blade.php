{{--
    The shell for pages a signed-OUT admin sees. Separate from the dashboard layout
    because it must not render a navigation bar, the current admin's name, or anything
    else that assumes somebody is authenticated.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['ar'], true) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- 🔒 Staff pages must never be indexed. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('admin.title') }}</title>
    @livewireStyles
</head>
<body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#faf9f5;font-family:system-ui,-apple-system,sans-serif;color:#1a1612">
    <main style="width:100%;max-width:380px;padding:24px">
        {{ $slot }}
    </main>
    @livewireScripts
</body>
</html>
