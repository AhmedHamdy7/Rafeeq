<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Driver\Actions\ReviewDriverApplicationAction;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Shared\Exceptions\DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
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

    public function mount(): void
    {
        $this->authorizeTo(AdminPermission::DriverView);
    }

    public function approve(string $id, ReviewDriverApplicationAction $action): void
    {
        $this->authorizeTo(AdminPermission::DriverDecide);

        $profile = $this->pending($id);
        $admin = Auth::guard('admin')->user();

        try {
            $before = ['status' => $profile->status->value];

            $action->approve($profile, $admin);

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
            $this->addError('queue', __('errors.'.$e->errorCode->value));

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
        ]);
    }
}
