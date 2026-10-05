<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\DashboardSummary;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The DASHBOARD section: one tile per queue this admin may open, each linking to it.
 *
 * 🔒 Each tile is gated on the permission of the page it links to, exactly like the rail's
 * badges: a count is small but it is still information about a queue somebody has no
 * business seeing. An admin with none of these permissions has no dashboard (see
 * HomeController).
 *
 * Polls every thirty seconds while visible, so a dashboard left open on a wall screen is
 * not a photograph of this morning.
 */
#[Layout('components.layouts.admin')]
class Dashboard extends Component
{
    /**
     * Any one of these is enough to have a dashboard.
     */
    public const array TILE_PERMISSIONS = [
        AdminPermission::SafetyView,
        AdminPermission::TripView,
        AdminPermission::VerificationView,
        AdminPermission::DriverView,
        AdminPermission::MemberView,
        AdminPermission::AuditLogView,
    ];

    public function mount(): void
    {
        abort_unless(self::isAvailableTo(Auth::guard('admin')->user()), 403);
    }

    public static function isAvailableTo(?AdminUser $admin): bool
    {
        foreach (self::TILE_PERMISSIONS as $permission) {
            if ($admin?->can($permission->value) === true) {
                return true;
            }
        }

        return false;
    }

    public function render()
    {
        $admin = Auth::guard('admin')->user();
        $may = fn (AdminPermission $permission) => $admin?->can($permission->value) === true;

        return view('livewire.admin.dashboard', [
            'safety' => $may(AdminPermission::SafetyView) ? DashboardSummary::safety() : null,
            'trips' => $may(AdminPermission::TripView) ? DashboardSummary::trips() : null,
            'seatsToday' => $may(AdminPermission::TripView) ? DashboardSummary::seatsToday() : null,
            'verifications' => $may(AdminPermission::VerificationView) ? DashboardSummary::verifications() : null,
            'driverApplications' => $may(AdminPermission::DriverView) ? DashboardSummary::driverApplications() : null,
            'overdueHolds' => $may(AdminPermission::MemberView) ? DashboardSummary::overdueHolds() : null,
            'recent' => $may(AdminPermission::AuditLogView)
                ? AdminAction::query()->with('admin')->orderByDesc('created_at')->orderByDesc('id')->limit(6)->get()
                : null,
        ])->title(__('admin.dashboard.title'));
    }
}
