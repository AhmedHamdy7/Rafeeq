{{--
    Delete your Rafeeq account (F1-4) — the web page Google Play's data-safety form links to.

    One template, five steps: phone → code → deleted | blocked | none. Written for somebody who
    may not have the app any more, so it says plainly what happens and what is kept.
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <title>امسح حسابك · رفيق</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px;
               background: #fbfbfd; color: #1c1c28;
               font-family: system-ui, -apple-system, "Segoe UI", sans-serif; }
        @media (prefers-color-scheme: dark) { body { background: #14141c; color: #f2f2f7; }
            input { background: #1f1f2a; color: #f2f2f7; border-color: #3a3a48; } }
        main { max-width: 380px; width: 100%; }
        h1 { font-size: 20px; margin: 0 0 12px; }
        p, li { font-size: 14px; line-height: 1.8; margin: 0 0 8px; opacity: .8; }
        ul { padding-inline-start: 20px; margin: 0 0 16px; }
        form { display: grid; gap: 12px; margin-top: 16px; }
        input { font-size: 16px; padding: 12px; border-radius: 10px; border: 1px solid #d6d6e0; }
        button { font-size: 16px; padding: 12px; border-radius: 10px; border: 0; background: #c62828; color: #fff; cursor: pointer; }
        .error { color: #c62828; opacity: 1; }
    </style>
</head>
<body>
<main>
    @if ($step === 'phone')
        <h1>امسح حسابك على رفيق</h1>
        <p>هنبعتلك كود على رقمك عشان نتأكد إنه بتاعك. بعد المسح:</p>
        <ul>
            <li>رحلاتك الجاية بتتلغي من غير أي رسوم، واللي معاك في الرحلة بيتبلّغ.</li>
            <li>اسمك وصورتك وإيميلك وأماكنك المحفوظة وجهات الاتصال بيتمسحوا.</li>
            <li>سجل الرحلات والمدفوعات والتقييمات والبلاغات بيفضل من غير اسم ولا رقم، لأن القانون والأمان محتاجينه.</li>
        </ul>
        <p>مينفعش تمسح الحساب وعليك فلوس لرفيق، أو عندك رحلة قريبة إلغاؤها عليه رسوم.</p>
        @if ($error)<p class="error">{{ $error }}</p>@endif
        <form method="post" action="{{ route('account-deletion.code') }}">
            @csrf
            <input name="phone" inputmode="tel" autocomplete="tel" placeholder="01xxxxxxxxx" required>
            <button type="submit">ابعتلي الكود</button>
        </form>
    @elseif ($step === 'code')
        <h1>اكتب الكود</h1>
        @isset($maskedPhone)<p>بعتنا كود على {{ $maskedPhone }}.</p>@endisset
        @if ($error)<p class="error">{{ $error }}</p>@endif
        <form method="post" action="{{ route('account-deletion.confirm') }}">
            @csrf
            <input name="code" inputmode="numeric" autocomplete="one-time-code" required>
            <button type="submit">امسح حسابي نهائيًا</button>
        </form>
    @elseif ($step === 'deleted')
        <h1>حسابك اتمسح</h1>
        <p>رحلاتك الجاية اتلغت وكل الأجهزة اتسجّل خروجها. لو حبيت ترجع، تقدر تسجّل من جديد بنفس الرقم.</p>
    @elseif ($step === 'blocked')
        <h1>مش هينفع دلوقتي</h1>
        <p class="error">{{ $reason }}</p>
    @else
        <h1>مفيش حساب على الرقم ده</h1>
        <p>مفيش حاجة محتاجة تتمسح.</p>
    @endif
</main>
</body>
</html>
