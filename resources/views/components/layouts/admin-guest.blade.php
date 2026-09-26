{{--
    The shell for pages a signed-OUT admin sees. Separate from the dashboard layout
    because it must not render the rail, the current admin's name, or the queue counts —
    all three assume somebody is authenticated, and two of them would leak.
--}}
<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}"
    data-theme="light"
>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- 🔒 Staff pages must never be indexed. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('admin.title') }}</title>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">

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
<body>
<div class="rq-guest">
    <main class="rq-guest__card">
        {{ $slot }}
    </main>
</div>
@livewireScripts
</body>
</html>
