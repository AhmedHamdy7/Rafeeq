<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rafeeq is bilingual (ar/en). The mobile app and admin dashboard both send
 * `Accept-Language`; we never guess the locale from anything else (no
 * per-user stored default lookup here — that belongs to authenticated
 * `users.preferred_language`, applied later once auth exists).
 */
final class SetLocaleFromHeader
{
    private const array SUPPORTED_LOCALES = ['ar', 'en'];

    private const string DEFAULT_LOCALE = 'ar';

    public function handle(Request $request, Closure $next): Response
    {
        $preferred = $request->getPreferredLanguage(self::SUPPORTED_LOCALES);

        app()->setLocale($preferred ?? self::DEFAULT_LOCALE);

        return $next($request);
    }
}
