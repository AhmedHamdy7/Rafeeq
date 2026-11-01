<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Driver\Actions\ReviewDriverApplicationAction;
use App\Domains\Driver\Actions\ReviewVehicleAction;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Enums\VehicleTier;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Support\VehicleSettings;
use App\Domains\Shared\Exceptions\DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The driver application queue — the dashboard's MEMBERS section.
 *
 * The other half of what was blocking the product: a driver could complete an
 * application and no surface existed to approve it, so no commute could ever be
 * published in production.
 *
 * Unlike the verification queue this one DOES have a reject, because the decision is
 * different in kind. A blurry ID is "try again"; a licence that belongs to somebody
 * else, or a car that cannot carry passengers, is a refusal of this application — and
 * `ReviewDriverApplicationAction::reject()` already exists, requires a reason, and
 * records it in the append-only `verification_logs`.
 *
 * 🔴 The car review happens here too (F2-7, ADM-01): before approving, the reviewer sets
 * each car's approved seats (the seats with working belts, at least 3 — PROF-28), the
 * Comfort badge (PROF-26) and whether its AC works. Approval refuses until every car has
 * approved seats, because a car without them cannot carry a published commute and the
 * seat price cannot be divided by them (PAY-42). Written through ReviewVehicleAction in the
 * same transaction as the approval, so a refused approval leaves no half-reviewed car.
 *
 * Oldest first, for the same reason as the verification queue: a queue ordered any
 * other way lets somebody wait indefinitely while the page looks productive.
 */
#[Layout('components.layouts.admin')]
class DriverApplications extends Component
{
    // Paged for the same reason as the verification queue: a cap on a queue ordered
    // oldest-first makes everybody past the cap invisible rather than merely later.
    use WithPagination;

    public ?string $openId = null;

    public string $reason = '';

    /**
     * The car review being filled in, keyed by vehicle id:
     * `['approvedSeats' => ?int, 'tier' => 'standard'|'comfort', 'hasAc' => bool]`.
     *
     * Approved seats are NOT prefilled from what the driver typed: the number is the reviewer's
     * answer, and a prefilled one is a number nobody decided.
     *
     * @var array<string, array{approvedSeats?: mixed, tier?: string, hasAc?: bool}>
     */
    public array $cars = [];

    public function mount(): void
    {
        $this->authorizeTo(AdminPermission::DriverView);
    }

    public function approve(string $id, ReviewDriverApplicationAction $action): void
    {
        $this->authorizeTo(AdminPermission::DriverDecide);

        $profile = $this->pending($id);
        $admin = Auth::guard('admin')->user();

        $reviews = $this->carReviews($profile);

        if ($reviews === null) {
            $this->addError('queue', __('admin.drivers.car.required'));

            return;
        }

        try {
            $before = ['status' => $profile->status->value];

            DB::transaction(function () use ($profile, $admin, $action, $reviews): void {
                foreach ($profile->vehicles as $vehicle) {
                    [$seats, $tier, $hasAc] = $reviews[$vehicle->id];

                    app(ReviewVehicleAction::class)->execute($vehicle, $admin, $seats, $tier, $hasAc);
                }

                $action->approve($profile, $admin);
            });

            AdminActionLog::record(
                $admin,
                'driver.approve',
                $profile,
                before: $before,
                after: ['status' => $profile->fresh()->status->value],
            );
        } catch (DomainException $e) {
            /*
             * The commonest failure here is a licence that expired while the
             * application sat in the queue — which is a real refusal to approve, not a
             * bug, and the reviewer needs to read it rather than see a blank page.
             */
            // A car review out of range says which range; anything else reads its error code.
            // F2-2: an ID or selfie still waiting in the verification queue is named, so the
            // reviewer knows where to go first.
            $this->addError('queue', $e->fields['approvedSeats'][0]
                ?? (isset($e->fields['missing']) ? __('admin.drivers.identity_not_approved', ['items' => implode(', ', $e->fields['missing'])]) : null)
                ?? __('errors.'.$e->errorCode->value));

            return;
        }

        $this->closeAndFlash(__('admin.drivers.approved', ['name' => $profile->user->full_name]));
    }

    public function reject(string $id, ReviewDriverApplicationAction $action): void
    {
        $this->authorizeTo(AdminPermission::DriverDecide);

        $this->validate([
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ], attributes: ['reason' => __('admin.queue.reason')]);

        $profile = $this->pending($id);
        $admin = Auth::guard('admin')->user();

        try {
            $before = ['status' => $profile->status->value];

            $action->reject($profile, $admin, $this->reason);

            AdminActionLog::record(
                $admin,
                'driver.reject',
                $profile,
                before: $before,
                after: ['status' => $profile->fresh()->status->value],
                reason: $this->reason,
            );
        } catch (DomainException $e) {
            $this->addError('reason', __('errors.'.$e->errorCode->value));

            return;
        }

        $this->reason = '';

        $this->closeAndFlash(__('admin.drivers.rejected', ['name' => $profile->user->full_name]));
    }

    /**
     * The car review for every car on the application, or null if any car has no approved
     * seats yet. The range itself is ReviewVehicleAction's to check, so the rule has one home.
     *
     * @return array<string, array{0: int, 1: VehicleTier, 2: bool}>|null
     */
    private function carReviews(DriverProfile $profile): ?array
    {
        $reviews = [];

        foreach ($profile->vehicles as $vehicle) {
            $car = $this->cars[$vehicle->id] ?? [];
            $seats = $car['approvedSeats'] ?? null;

            if (! is_numeric($seats)) {
                return null;
            }

            $reviews[$vehicle->id] = [
                (int) $seats,
                VehicleTier::tryFrom((string) ($car['tier'] ?? '')) ?? VehicleTier::Standard,
                (bool) ($car['hasAc'] ?? false),
            ];
        }

        return $reviews;
    }

    /**
     * Re-read on every action: another reviewer may have decided this one since the
     * page was rendered.
     */
    private function pending(string $id): DriverProfile
    {
        $profile = DriverProfile::query()
            ->whereKey($id)
            ->where('status', DriverProfileStatus::PendingReview->value)
            ->with('user', 'vehicles')
            ->first();

        // 404, not a DomainException — see the note in VerificationQueue::pending().
        abort_if($profile === null, 404);

        return $profile;
    }

    private function authorizeTo(AdminPermission $permission): void
    {
        abort_unless(
            Auth::guard('admin')->user()?->can($permission->value) === true,
            403,
        );
    }

    private function closeAndFlash(string $message): void
    {
        $this->openId = null;

        session()->flash('status', $message);

        // Deciding the last application on the last page would otherwise leave the
        // reviewer looking at an empty page past the end.
        $page = $this->pendingPage();

        if ($page->currentPage() > $page->lastPage()) {
            $this->setPage($page->lastPage());
        }
    }

    /**
     * @return LengthAwarePaginator<int, DriverProfile>
     */
    private function pendingPage()
    {
        return DriverProfile::query()
            ->where('status', DriverProfileStatus::PendingReview->value)
            ->with('user', 'vehicles')
            ->orderBy('updated_at')
            ->orderBy('user_id')
            ->paginate(10);
    }

    public function render()
    {
        return view('livewire.admin.driver-applications', [
            'applications' => $this->pendingPage(),
            'mayDecide' => Auth::guard('admin')->user()?->can(AdminPermission::DriverDecide->value) === true,
            'minApprovedSeats' => VehicleSettings::minApprovedSeats(),
            'maxApprovedSeats' => (int) config('rafeeq.driver.max_approved_seats'),
        ]);
    }
}
