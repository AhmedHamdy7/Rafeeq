<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Signing a staff member out of the dashboard.
 *
 * A controller rather than the closure this used to be, so the three steps below can be read — and
 * tested — in one place instead of inline in the route table. (Closure routes cache fine; modern
 * Laravel serialises them. That is worth saying because it is easy to assume otherwise.)
 *
 * 🔒 POST, and therefore CSRF-protected. A GET sign-out can be triggered by anything that makes a
 * browser load a URL — a nuisance here, and a real problem on the actions next door.
 */
final class LogoutController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        Auth::guard('admin')->logout();

        /*
         * Both, in this order. Invalidating alone leaves the old CSRF token valid for the next
         * session, and regenerating alone leaves the session's own data readable.
         */
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
