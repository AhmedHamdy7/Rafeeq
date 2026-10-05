<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Admin\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Where a staff member lands after signing in: the first page their role can open.
 *
 * 🔴 Signing in used to go straight to the verification queue, which was right while that
 * was the only page. A safety lead or a finance admin does not hold `verification.view`,
 * so their first screen after a successful two-step sign-in was a bare 403 — on the desk
 * that answers SOS alerts, of all of them.
 *
 * The order is the order of urgency: an alert waiting for a human outranks a car on the
 * road, which outranks a document waiting for review.
 */
final class HomeController extends Controller
{
    private const array LANDINGS = [
        'admin.safety' => AdminPermission::SafetyView,
        'admin.trips' => AdminPermission::TripView,
        'admin.verifications' => AdminPermission::VerificationView,
        'admin.drivers' => AdminPermission::DriverView,
        'admin.members' => AdminPermission::MemberView,
    ];

    public function __invoke(): RedirectResponse
    {
        $admin = Auth::guard('admin')->user();

        foreach (self::LANDINGS as $route => $permission) {
            if ($admin?->can($permission->value) === true) {
                return redirect()->route($route);
            }
        }

        /*
         * A role with no page yet (finance, until PAYMENTS exists). Said plainly rather than
         * bounced to a page that refuses them.
         */
        abort(403, __('admin.home.nothing_yet'));
    }
}
