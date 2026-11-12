<?php

namespace App\Domains\Admin\Support;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Models\AdminUser;
use App\Livewire\Admin\Appeals;
use App\Livewire\Admin\Dashboard;
use Illuminate\Support\Facades\Route;

/**
 * The dashboard's sidebar, in one place: the groups, their order, each link's icon,
 * the permission that shows it and the count badge beside it.
 *
 * The order and the groups follow the dashboard design (Overview · Live · Queues ·
 * Directory · Money · Communication · Configuration · Team). A link appears only when
 * its page exists (`Route::has`) AND this staff member may open it, so a section of
 * the design that is not built yet simply is not in the menu, and nobody is shown a
 * door that answers 403. 🔒 Hiding a link is NOT the permission check: every page
 * checks again.
 *
 * Badges are named, not counted, here: the counts come from the view composer in
 * AppServiceProvider, which already gates each one on the page's own permission.
 */
final class Navigation
{
    /**
     * @return list<array{key: string, items: list<array{key: string, route: string, icon: string, badge: ?string, tone: string}>}>
     */
    public static function for(?AdminUser $admin): array
    {
        $groups = [];

        foreach (self::definition() as $group => $items) {
            $visible = [];

            foreach ($items as $item) {
                if (! Route::has($item['route']) || ! self::allows($admin, $item['can'])) {
                    continue;
                }

                $visible[] = [
                    'key' => $item['key'],
                    'route' => $item['route'],
                    'icon' => $item['icon'],
                    'badge' => $item['badge'] ?? null,
                    'tone' => $item['tone'] ?? 'muted',
                ];
            }

            if ($visible !== []) {
                $groups[] = ['key' => $group, 'items' => $visible];
            }
        }

        return $groups;
    }

    /**
     * `can` is a permission, a list of permissions (any of them), or a callable for
     * pages whose rule is more than one permission.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function definition(): array
    {
        return [
            'overview' => [
                ['key' => 'home', 'route' => 'admin.dashboard', 'icon' => 'home', 'can' => fn (?AdminUser $admin): bool => Dashboard::isAvailableTo($admin)],
                ['key' => 'statistics', 'route' => 'admin.statistics', 'icon' => 'chart', 'can' => AdminPermission::PaymentView],
            ],
            'live' => [
                ['key' => 'sos', 'route' => 'admin.safety', 'icon' => 'alert', 'can' => AdminPermission::SafetyView, 'badge' => 'openSafetyCases', 'tone' => 'bad'],
                ['key' => 'live_trips', 'route' => 'admin.trips', 'icon' => 'radio', 'can' => AdminPermission::TripView, 'badge' => 'liveTrips'],
                ['key' => 'escort', 'route' => 'admin.escort', 'icon' => 'moon', 'can' => AdminPermission::SafetyView],
            ],
            'queues' => [
                ['key' => 'driver_verification', 'route' => 'admin.drivers', 'icon' => 'id', 'can' => AdminPermission::DriverView, 'badge' => 'pendingDrivers', 'tone' => 'bad'],
                ['key' => 'identity', 'route' => 'admin.verifications', 'icon' => 'shield', 'can' => AdminPermission::VerificationView, 'badge' => 'pendingVerifications', 'tone' => 'warn'],
                ['key' => 'replacement_cars', 'route' => 'admin.replacement-cars', 'icon' => 'swap', 'can' => AdminPermission::DriverView, 'badge' => 'pendingReplacementCars', 'tone' => 'warn'],
                ['key' => 'reports', 'route' => 'admin.reports', 'icon' => 'flag', 'can' => AdminPermission::SafetyView],
                ['key' => 'appeals', 'route' => 'admin.appeals', 'icon' => 'inbox', 'can' => fn (?AdminUser $admin): bool => Appeals::isAvailableTo($admin)],
                ['key' => 'payments', 'route' => 'admin.payments', 'icon' => 'receipt', 'can' => AdminPermission::PaymentView],
                ['key' => 'coupons', 'route' => 'admin.coupons', 'icon' => 'gift', 'can' => AdminPermission::PaymentView],
            ],
            'directory' => [
                ['key' => 'users', 'route' => 'admin.members', 'icon' => 'users', 'can' => AdminPermission::MemberView, 'badge' => 'overdueHolds', 'tone' => 'bad'],
                ['key' => 'vehicles', 'route' => 'admin.vehicles', 'icon' => 'car', 'can' => AdminPermission::DriverView],
                ['key' => 'commutes', 'route' => 'admin.commutes', 'icon' => 'route', 'can' => AdminPermission::CommuteView],
                ['key' => 'trip_days', 'route' => 'admin.trip-days', 'icon' => 'calendar', 'can' => AdminPermission::TripView],
                ['key' => 'bookings', 'route' => 'admin.bookings', 'icon' => 'ticket', 'can' => AdminPermission::CommuteView],
                ['key' => 'demands', 'route' => 'admin.demands', 'icon' => 'target', 'can' => AdminPermission::CommuteView],
            ],
            'money' => [
                ['key' => 'settlements', 'route' => 'admin.settlements', 'icon' => 'scale', 'can' => AdminPermission::PaymentView],
                ['key' => 'ledger', 'route' => 'admin.ledger', 'icon' => 'book', 'can' => AdminPermission::PaymentView],
            ],
            'communication' => [
                ['key' => 'announcements', 'route' => 'admin.announcements', 'icon' => 'megaphone', 'can' => AdminPermission::SettingsManage],
                ['key' => 'support', 'route' => 'admin.support', 'icon' => 'headset', 'can' => AdminPermission::TicketView],
            ],
            'configuration' => [
                ['key' => 'settings', 'route' => 'admin.settings', 'icon' => 'settings', 'can' => AdminPermission::SettingsManage],
                ['key' => 'content', 'route' => 'admin.content', 'icon' => 'file', 'can' => AdminPermission::SettingsManage],
            ],
            'team' => [
                ['key' => 'team', 'route' => 'admin.team', 'icon' => 'users', 'can' => AdminPermission::AdminManage],
                ['key' => 'audit', 'route' => 'admin.audit', 'icon' => 'file', 'can' => AdminPermission::AuditLogView],
            ],
        ];
    }

    private static function allows(?AdminUser $admin, mixed $can): bool
    {
        if ($admin === null) {
            return false;
        }

        if (is_callable($can)) {
            return $can($admin) === true;
        }

        foreach (is_array($can) ? $can : [$can] as $permission) {
            if ($admin->can($permission->value)) {
                return true;
            }
        }

        return false;
    }
}
