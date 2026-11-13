<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\DashboardSummary;
use App\Domains\Admin\Support\HomeWatch;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\Vehicle;
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

    /** The Home's cards, in order. Each staff member may hide the ones they do not use (Customise). */
    public const array CARDS = ['live', 'queues', 'watch', 'money', 'team'];

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

    /**
     * Customise: saved per staff member. Unknown names are dropped; choosing all of them (or
     * none) stores null, which means "everything", so a new card later shows up for everybody.
     *
     * @param  array<int, string>  $cards
     */
    public function saveCards(array $cards): void
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof AdminUser, 403);

        $chosen = array_values(array_intersect(self::CARDS, array_map('strval', $cards)));

        $admin->forceFill(['home_cards' => $chosen === [] || count($chosen) === count(self::CARDS) ? null : $chosen])->save();
    }

    /** @return list<string> */
    public static function cardsFor(?AdminUser $admin): array
    {
        $saved = $admin?->home_cards;

        return is_array($saved) && $saved !== [] ? array_values(array_intersect(self::CARDS, $saved)) : self::CARDS;
    }

    /** Same count as the sidebar badge and the Replacement cars page. */
    private function replacementCars(): int
    {
        return Vehicle::query()
            ->where('verification_status', VehicleVerificationStatus::Pending->value)
            ->whereHas('driverProfile', fn ($query) => $query->where('status', DriverProfileStatus::Approved->value)->whereHas('user'))
            ->count();
    }

    public function render()
    {
        $admin = Auth::guard('admin')->user();
        $may = fn (AdminPermission $permission) => $admin?->can($permission->value) === true;
        $cards = self::cardsFor($admin instanceof AdminUser ? $admin : null);
        $shows = fn (string $card) => in_array($card, $cards, true);

        return view('livewire.admin.dashboard', [
            'safety' => $may(AdminPermission::SafetyView) ? DashboardSummary::safety() : null,
            'escort' => $may(AdminPermission::SafetyView) ? DashboardSummary::escort() : null,
            'trips' => $may(AdminPermission::TripView) ? DashboardSummary::trips() : null,
            'seatsToday' => $may(AdminPermission::TripView) ? DashboardSummary::seatsToday() : null,
            'verifications' => $may(AdminPermission::VerificationView) ? DashboardSummary::verifications() : null,
            'driverApplications' => $may(AdminPermission::DriverView) ? DashboardSummary::driverApplications() : null,
            'overdueHolds' => $may(AdminPermission::MemberView) ? DashboardSummary::overdueHolds() : null,
            'tripDays' => $may(AdminPermission::TripView) ? DashboardSummary::tripDays() : null,
            'replacementCars' => $may(AdminPermission::DriverView) ? $this->replacementCars() : null,
            'payments' => $may(AdminPermission::PaymentView) ? DashboardSummary::paymentsToConfirm() : null,
            'appeals' => $may(AdminPermission::PaymentView) || $may(AdminPermission::MemberView)
                ? DashboardSummary::appeals($may(AdminPermission::PaymentView), $may(AdminPermission::MemberView))
                : null,
            'admin' => $admin,
            'cards' => $cards,
            // Watch list (H03): each line only for staff who may open the page that deals with it.
            'watchDebts' => $shows('watch') && $may(AdminPermission::PaymentView) ? HomeWatch::debts() : null,
            'watchPapers' => $shows('watch') && $may(AdminPermission::DriverView) ? HomeWatch::expiringPapers() : null,
            'watchGender' => $shows('watch') && $may(AdminPermission::CommuteView) ? HomeWatch::genderReviewCommutes() : null,
            // Money snapshot (H04): Finance and Admin only.
            'money' => $shows('money') && $may(AdminPermission::PaymentView) ? HomeWatch::money() : null,
            'recent' => $may(AdminPermission::AuditLogView)
                ? AdminAction::query()->with('admin')->orderByDesc('created_at')->orderByDesc('id')->limit(6)->get()
                : null,
        ])->title(__('admin.dashboard.title'));
    }
}
