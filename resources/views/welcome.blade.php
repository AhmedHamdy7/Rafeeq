{{--
    The root page.

    🔴 Deliberately asset-free. This was Laravel's default welcome page, which pulls in
    `@vite(...)` — and that one `@vite` call was the only reason the whole Node
    toolchain existed in the build. On a small builder its prune step gets OOM-killed
    (exit 137), so the build failed for a page that is not part of the product.

    It also should not have been the Laravel default page on a public server: that
    advertises a fresh install and tells a passer-by which framework and version to
    look up.

    Rafeeq is an API plus an admin dashboard. There is no public web product, so this
    page says what the host is and where the two real surfaces are, and nothing else.

    No link to /docs/api on purpose: whether the API contract should be publicly
    readable is a decision per environment, not something to advertise from the root.
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>RAFEEQ</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #fbfbfd;
            color: #1c1c28;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #14141c; color: #f2f2f7; }
        }
        main { text-align: center; padding: 24px; }
        h1 { font-size: 20px; font-weight: 700; letter-spacing: -.01em; margin: 0 0 8px; }
        p { margin: 0; font-size: 14px; opacity: .65; }
        a { color: #6d28d9; }
    </style>
</head>
<body>
    <main>
        <h1>RAFEEQ</h1>
        <p>منصّة مشاركة المشاوير اليومية المخطَّطة.</p>
        <p style="margin-top:16px"><a href="{{ route('admin.login') }}">لوحة التحكم</a></p>
    </main>
</body>
</html>
