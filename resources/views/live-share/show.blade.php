{{--
    The page a trusted contact opens.

    🔒 Asset-free and self-contained on purpose. No `@vite`, no external font, no map tile
    provider — because every external request from this page sends the referrer, and the referrer
    is the URL, and the URL IS the credential. `Referrer-Policy: no-referrer` covers it, but a page
    that makes no third-party requests at all cannot leak the token however that header is
    handled downstream.

    It also means the page renders on a bad connection, which is the situation somebody checking
    on a relative is usually in.

    What is deliberately absent is in LiveShareController's docblock: no phone numbers, no full
    names, no other passengers, no GPS history, no ids.
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <title>رحلة مباشرة · رفيق</title>
    <style>
        :root { color-scheme: light dark; --bg: #fbfbfd; --card: #fff; --fg: #1c1c28; --muted: #6b6b7b; --line: #e6e6ef; --brand: #6d28d9; --ok: #0f9d58; --warn: #b45309; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #14141c; --card: #1d1d28; --fg: #f2f2f7; --muted: #9a9aad; --line: #2c2c3a; --brand: #a78bfa; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--fg); font-family: system-ui, -apple-system, "Segoe UI", sans-serif; padding: 20px 16px 40px; }
        main { max-width: 480px; margin: 0 auto; }
        .brand { font-size: 12px; font-weight: 700; letter-spacing: .08em; color: var(--brand); margin-bottom: 14px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 18px; margin-bottom: 12px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .sub { font-size: 13px; color: var(--muted); margin: 0; }
        .row { display: flex; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--line); font-size: 14px; }
        .row:last-child { border-bottom: 0; }
        .row span:first-child { color: var(--muted); }
        .row span:last-child { font-weight: 600; text-align: end; }
        .pill { display: inline-block; font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 999px; }
        .pill-live { background: rgba(15,157,88,.12); color: var(--ok); }
        .pill-idle { background: rgba(180,83,9,.12); color: var(--warn); }
        .note { font-size: 12px; color: var(--muted); line-height: 1.7; margin: 16px 0 0; }
        a { color: var(--brand); }
    </style>
</head>
<body>
<main>
    <p class="brand">رفيق · رحلة مباشرة</p>

    <div class="card">
        <h1>{{ $driverName }}</h1>
        <p class="sub">
            @if ($isUnderway)
                <span class="pill pill-live">الرحلة جارية</span>
            @else
                <span class="pill pill-idle">الرحلة مش جارية دلوقتي</span>
            @endif
        </p>
    </div>

    <div class="card">
        <div class="row">
            <span>العربية</span>
            <span>{{ trim(($vehicle['colour'] ?? '').' '.($vehicle['make'] ?? '').' '.($vehicle['model'] ?? '')) ?: '—' }}</span>
        </div>
        <div class="row">
            <span>اللوحة</span>
            <span>{{ $vehicle['plate'] ?? '—' }}</span>
        </div>
        <div class="row">
            <span>الوجهة</span>
            <span>{{ $destinationLabel ?? '—' }}</span>
        </div>
        <div class="row">
            <span>ميعاد القيام</span>
            <span>{{ $dueAt?->timezone('Africa/Cairo')->format('H:i') ?? '—' }}</span>
        </div>
    </div>

    <div class="card">
        @if ($position !== null)
            <div class="row">
                <span>آخر موقع</span>
                <span>{{ number_format($position['lat'], 5) }}, {{ number_format($position['lng'], 5) }}</span>
            </div>
            <div class="row">
                <span>وقت آخر تحديث</span>
                <span>{{ $lastSeenAt?->timezone('Africa/Cairo')->format('H:i:s') ?? '—' }}</span>
            </div>
        @else
            {{--
                🔒 Said plainly rather than hidden. A contact looking at a stale dot would believe
                the car had stopped there — which is the one wrong conclusion this page could lead
                somebody to in an emergency.
            --}}
            <div class="row">
                <span>الموقع</span>
                <span>مفيش تحديث حديث</span>
            </div>
        @endif
    </div>

    <p class="note">
        الصفحة دي مؤقتة وبتنتهي
        {{ $expiresAt->timezone('Africa/Cairo')->format('H:i') }}،
        أو أول ما اللي شاركها يوقفها.
        <br>
        بتعرض الاسم الأول والعربية والموقع بس — مفيش أرقام تليفونات ولا عناوين.
    </p>
</main>
</body>
</html>
