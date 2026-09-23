<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rafeeq is bilingual (ar/en).
 *
 * `Accept-Language` wins when it is actually sent, because that is the
 * client stating what THIS request should be rendered in. When it is
 * absent — a push-triggered call, a webhook-style client, curl — an
 * authenticated caller's stored `preferred_language` is used, so someone
 * who chose Arabic in the app never receives an English error because a
 * header went missing. Arabic is the last resort, matching the product's
 * primary audience.
 */
final class SetLocaleFromHeader
{
    private const array SUPPORTED_LOCALES = ['ar', 'en'];

    private const string DEFAULT_LOCALE = 'ar';

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolveLocale($request));

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        $requested = $this->firstSupportedLanguage($request);

        if ($requested !== null) {
            return $requested;
        }

        // Explicitly the `sanctum` guard: this middleware runs before the
        // route's `auth:sanctum`, so the default guard would be the session
        // one and would never see a bearer token. A missing or invalid token
        // simply yields null here — it is never this middleware's job to
        // reject a request.
        $stored = $request->user('sanctum')?->preferred_language;

        return in_array($stored, self::SUPPORTED_LOCALES, true)
            ? $stored
            : self::DEFAULT_LOCALE;
    }

    /**
     * Walks the header in the client's own order of preference and returns
     * the first language Rafeeq actually speaks, or null when it speaks
     * none of them.
     *
     * Deliberately not `getPreferredLanguage()`: that method returns the
     * first locale it was handed when NOTHING matches, so "the client asked
     * for Arabic" and "the client asked for French and we gave up" come
     * back as the same answer — which would make the stored-preference
     * fallback below unreachable.
     */
    private function firstSupportedLanguage(Request $request): ?string
    {
        foreach ($request->getLanguages() as $language) {
            // Symfony normalises to `en_US`; the primary subtag is what we
            // match on, so `ar-EG` and `ar` are both Arabic.
            $primary = strtolower(substr($language, 0, 2));

            if (in_array($primary, self::SUPPORTED_LOCALES, true)) {
                return $primary;
            }
        }

        return null;
    }
}
