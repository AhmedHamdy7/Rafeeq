<?php

namespace App\Http\Controllers\Admin;

use App\Http\Middleware\SetAdminLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Switches the dashboard between Arabic and English.
 *
 * POST (CSRF-protected), like sign-out: changing state is never a GET. Open to signed-out
 * staff too, so the sign-in page can be read in either language.
 */
class LocaleController
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, SetAdminLocale::LOCALES, true), 404);

        $request->session()->put(SetAdminLocale::SESSION_KEY, $locale);

        // Back to the page the switch was pressed on, but only ever inside the dashboard.
        $back = url()->previous();

        return redirect()->to(str_starts_with($back, url('/admin')) ? $back : route('admin.home'));
    }
}
