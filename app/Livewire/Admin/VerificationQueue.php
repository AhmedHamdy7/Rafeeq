<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Verification\Actions\ReviewVerificationAction;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Models\UserVerification;
use App\Domains\Verification\Support\VerificationQueue as Queue;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The identity review queue — the dashboard page that unblocks the whole product.
 *
 * Until this existed, `ReviewVerificationAction` was reachable only from the test suite
 * and the seeder, which meant nobody could be verified or approved in production at all.
 *
 * Two verdicts, matching the prototype's two buttons: **Approve**, and **Ask info** with
 * a reason the person reads verbatim. There is no reject button — see
 * {@see ReviewVerificationAction::requestMoreInfo()} for why the terminal state is
 * attempt exhaustion rather than a reviewer closing a door in one click.
 *
 * 🔒 Three separate permissions are checked, not one:
 *
 * - `verification.view` to see the queue at all
 * - `verification.decide` to approve or ask for information
 * - `verification.view_document` to open the image
 *
 * A support agent can answer "is my verification moving" without ever seeing a national
 * ID. That split is the difference between one phished support account leaking a status
 * and one phished support account leaking every identity document on the platform.
 *
 * Authorisation is re-checked inside each action, never only on render: a Livewire
 * action is a POST a browser can make directly, so a hidden button is not a permission
 * check.
 */
#[Layout('components.layouts.admin')]
class VerificationQueue extends Component
{
    /*
     * Paging over a queue that is decided FROM, which is unusual enough to state: rows
     * leave the list as they are approved, so the page under the reviewer shrinks as
     * they work. Deciding the last item on page 2 would otherwise leave them on an empty
     * page 2 of a 1-page list — see `keepThePageInRange()`.
     */
    use WithPagination;

    /** Which card is expanded, if any. */
    public ?string $openId = null;

    /** The reason being typed for "Ask info", per verification id. */
    public string $reason = '';

    public function mount(): void
    {
        $this->authorizeTo(AdminPermission::VerificationView);
    }

    public function approve(string $id, ReviewVerificationAction $action): void
    {
        $this->authorizeTo(AdminPermission::VerificationDecide);

        $verification = $this->pending($id);
        $admin = Auth::guard('admin')->user();

        try {
            $before = ['status' => $verification->status->value];

            $action->approve($verification, $admin);

            AdminActionLog::record(
                $admin,
                'verification.approve',
                $verification,
                before: $before,
                after: ['status' => $verification->fresh()->status->value],
            );
        } catch (DomainException $e) {
            $this->addError('queue', __('errors.'.$e->errorCode->value));

            return;
        }

        $this->closeAndFlash(__('admin.queue.approved', ['name' => $verification->user->full_name]));
    }

    public function requestInfo(string $id, ReviewVerificationAction $action): void
    {
        $this->authorizeTo(AdminPermission::VerificationDecide);

        /*
         * Validated here as well as in the Action. The Action's check is the guarantee;
         * this one turns it into a field error next to the box instead of a thrown
         * exception, because the reviewer is mid-sentence and the message they write is
         * shown to the person verbatim.
         */
        $this->validate([
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ], attributes: ['reason' => __('admin.queue.reason')]);

        $verification = $this->pending($id);
        $admin = Auth::guard('admin')->user();

        try {
            $before = ['status' => $verification->status->value];

            $action->requestMoreInfo($verification, $admin, $this->reason);

            AdminActionLog::record(
                $admin,
                'verification.request_info',
                $verification,
                before: $before,
                after: ['status' => $verification->fresh()->status->value],
                reason: $this->reason,
            );
        } catch (DomainException $e) {
            $this->addError('reason', __('errors.'.$e->errorCode->value));

            return;
        }

        $this->reason = '';

        $this->closeAndFlash(__('admin.queue.info_requested', ['name' => $verification->user->full_name]));
    }

    /**
     * A level that is actually waiting for a decision.
     *
     * Re-read from the database on every action rather than trusted from the component's
     * state: between rendering the queue and clicking a button, another reviewer may
     * have decided this one. The Action refuses a non-pending level anyway — this makes
     * the refusal a clear message instead of a race.
     */
    private function pending(string $id): UserVerification
    {
        $verification = UserVerification::query()
            ->whereKey($id)
            ->where('status', VerificationStatus::Pending->value)
            ->with('user')
            ->first();

        /*
         * `abort(404)`, not a DomainException: that exception is rendered into the API's
         * error envelope by `ApiExceptionHandler`, which never runs for a web request —
         * so throwing it here produced a 500 where the honest answer is "that row is not
         * waiting any more".
         */
        abort_if($verification === null, 404);

        return $verification;
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

        $this->keepThePageInRange();
    }

    /**
     * Steps back a page when the one being read no longer exists.
     *
     * Rows leave this queue as they are decided, so clearing the last item on the last
     * page leaves the reviewer on a page past the end — an empty screen that reads as
     * "nothing waiting" while people are still queued behind them.
     */
    private function keepThePageInRange(): void
    {
        $page = Queue::pending($this->perPage());

        if ($page->currentPage() > $page->lastPage()) {
            $this->setPage($page->lastPage());
        }
    }

    /**
     * How many cards a reviewer sees at once. Smaller than an API page: each card is a
     * person to read rather than a row to scan.
     */
    private function perPage(): int
    {
        return 10;
    }

    public function render()
    {
        return view('livewire.admin.verification-queue', [
            'cards' => Queue::pending($this->perPage()),
            'mayDecide' => Auth::guard('admin')->user()?->can(AdminPermission::VerificationDecide->value) === true,
            'maySeeDocuments' => Auth::guard('admin')->user()?->can(AdminPermission::VerificationViewDocument->value) === true,
        ]);
    }
}
