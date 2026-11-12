<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The dashboard's language: Arabic or English, chosen by the staff member and kept in
 * their session.
 *
 * Separate from the API's SetLocaleFromHeader on purpose: the app sends
 * Accept-Language on every call, a browser sends whatever the operating system says,
 * and a staff member who picked Arabic should not be switched back to English because
 * their laptop is set up in English.
 *
 * Registered as Livewire persistent middleware too (AppServiceProvider), so a click
 * that re-renders a component answers in the same language as the page around it.
 */
class SetAdminLocale
{
    public const SESSION_KEY = 'admin_locale';

    /** @var list<string> */
    public const LOCALES = ['en', 'ar'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if (is_string($locale) && in_array($locale, self::LOCALES, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
