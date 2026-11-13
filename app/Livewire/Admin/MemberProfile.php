<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Actions\ManageDebtAction;
use App\Domains\Admin\Actions\ManageMemberAction;
use App\Domains\Admin\Actions\ModerateMemberRecordAction;
use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Models\MemberNote;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Identity\Enums\Gender;
use App\Domains\Identity\Models\Device;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Notification;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Payment\Support\DebtStanding;
use App\Domains\Rating\Models\Rating;
use App\Domains\Reliability\Models\ReliabilityEvent;
use App\Domains\Safety\Enums\IncidentStatus;
use App\Domains\Safety\Models\Incident;
use App\Livewire\Admin\Concerns\ShowsMemberDetails;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * USER DETAIL (D01–D10, D15): one member on a page of their own — header, the money and
 * dispute banners, and a tab per side of them: overview, as passenger, as driver, money,
 * reliability and ratings, reports, notifications, devices, notes and activity.
 *
 * Decisions (suspend, reinstate, suspend the driver role, decide an appeal) stay where
 * their rules already live — the Users page's panel — and are one click away. This page
 * adds what reading a member needs: team notes, and revealing the phone number with a
 * reason that goes to the audit log (D15).
 */
#[Layout('components.layouts.admin')]
class MemberProfile extends Component
{
    use ShowsMemberDetails;

    public const array TABS = ['overview', 'passenger', 'driver', 'money', 'reliability', 'reports', 'notifications', 'devices', 'notes'];

    #[Locked]
    public string $userId = '';

    #[Url(as: 'tab')]
    public string $tab = 'overview';

    public string $note = '';

    public bool $revealing = false;

    public string $revealReason = '';

    /** The full phone number, only for the render right after a reasoned reveal. */
    public ?string $revealedPhone = null;

    /** The open "More actions" form: correct, write_off, credit, unlock, escalate — or ''. */
    public string $action = '';

    /** The reliability event or rating a record action is about. */
    public string $target = '';

    /** D19 · the message's title; its text goes in `reason`. */
    public string $messageTitle = '';

    public string $reason = '';

    public string $fullName = '';

    public string $gender = '';

    public string $dateOfBirth = '';

    public string $side = 'driver';

    public string $amount = '';

    /** One line saying what the last action did, shown above the tabs. */
    public ?string $done = null;

    public function mount(User $user): void
    {
        $this->authorizeTo(AdminPermission::MemberView);

        $this->userId = $user->id;
        $this->tab = in_array($this->tab, $this->tabs(), true) ? $this->tab : 'overview';
    }

    public function pick(string $tab): void
    {
        $this->tab = in_array($tab, $this->tabs(), true) ? $tab : 'overview';
    }

    public function addNote(): void
    {
        $this->authorizeTo(AdminPermission::MemberView);

        $this->validate(['note' => ['required', 'string', 'min:3', 'max:2000']], attributes: ['note' => __('admin/profile.note')]);

        MemberNote::query()->create(['user_id' => $this->userId, 'admin_id' => $this->admin()->id, 'body' => trim($this->note)]);

        $this->reset('note');
    }

    public function reveal(): void
    {
        $this->authorizeTo(AdminPermission::MemberView);

        $this->validate(['revealReason' => ['required', 'string', 'min:5', 'max:255']], attributes: ['revealReason' => __('admin/profile.reveal_reason')]);

        $user = $this->member();

        AdminActionLog::record($this->admin(), 'member.reveal_phone', $user, reason: trim($this->revealReason));

        $this->revealedPhone = $user->phone_e164;
        $this->reset('revealing', 'revealReason');
    }

    public const array RECORD_ACTIONS = ['reverse_event', 'decline_appeal', 'hide_rating', 'restore_rating'];

    public function startAction(string $action, string $target = ''): void
    {
        $this->resetValidation();
        $this->reset('reason', 'amount', 'done', 'messageTitle');
        $this->action = in_array($action, ['correct', 'write_off', 'credit', 'unlock', 'escalate', 'message', ...self::RECORD_ACTIONS], true) ? $action : '';
        $this->target = in_array($this->action, self::RECORD_ACTIONS, true) ? $target : '';

        if ($this->action === 'correct') {
            $user = $this->member();
            $this->fullName = (string) $user->full_name;
            $this->gender = $user->gender instanceof \BackedEnum ? $user->gender->value : (string) $user->gender;
            $this->dateOfBirth = (string) $user->date_of_birth?->format('Y-m-d');
        }
    }

    public function runAction(ManageMemberAction $members, ManageDebtAction $debts, ModerateMemberRecordAction $records): void
    {
        $rules = ['reason' => ['required', 'string', 'min:5', 'max:255']];

        // The action's own rules win, so a message may be longer than a reason.
        $rules = match ($this->action) {
            'correct' => [
                'fullName' => ['required', 'string', 'min:2', 'max:150'],
                'gender' => ['required', 'string', Rule::enum(Gender::class)],
                'dateOfBirth' => ['nullable', 'date', 'before:-15 years'],
            ],
            'write_off' => ['side' => ['required', 'string', Rule::enum(DebtSide::class)]],
            'message' => ['messageTitle' => ['required', 'string', 'min:2', 'max:120'], 'reason' => ['required', 'string', 'min:2', 'max:1000']],
            'credit' => ['side' => ['required', 'string', Rule::enum(DebtSide::class)], 'amount' => ['required', 'numeric', 'min:0.01', 'max:100000']],
            default => [],
        } + $rules;

        $this->validate($rules, attributes: ['reason' => __('admin/actions.reason')]);

        $user = $this->member();
        $admin = $this->admin();

        $this->done = match ($this->action) {
            'correct' => (function () use ($members, $admin, $user) {
                $members->correct($admin, $user, ['full_name' => $this->fullName, 'gender' => $this->gender, 'date_of_birth' => $this->dateOfBirth], $this->reason);

                return __('admin/actions.done.correct');
            })(),
            'write_off' => __('admin/actions.done.write_off', ['amount' => number_format($members->writeOff($admin, $user, DebtSide::from($this->side), $this->reason) / 100, 2)]),
            'credit' => (function () use ($debts, $admin, $user) {
                abort_unless($admin->can(AdminPermission::DebtManage->value), 403);
                $debts->credit($user, DebtSide::from($this->side), (int) round(((float) $this->amount) * 100), $admin, $this->reason);

                return __('admin/actions.done.credit');
            })(),
            'unlock' => (function () use ($members, $admin, $user) {
                $members->unlockSignIn($admin, $user, $this->reason);

                return __('admin/actions.done.unlock');
            })(),
            'message' => (function () use ($members, $admin, $user) {
                $members->message($admin, $user, $this->messageTitle, $this->reason);

                return __('admin/actions.done.message');
            })(),
            'escalate' => (function () use ($members, $admin, $user) {
                $members->escalate($admin, $user, $this->reason);

                return __('admin/actions.done.escalate');
            })(),
            'reverse_event', 'decline_appeal' => (function () use ($records, $admin, $user) {
                $event = ReliabilityEvent::query()->whereKey($this->target)->where('user_id', $user->id)->firstOrFail();
                $this->action === 'reverse_event'
                    ? $records->reverseEvent($admin, $user, $event, $this->reason)
                    : $records->declineAppeal($admin, $user, $event, $this->reason);

                return __('admin/actions.done.'.$this->action);
            })(),
            'hide_rating', 'restore_rating' => (function () use ($records, $admin, $user) {
                $rating = Rating::query()->whereKey($this->target)->where('reviewed_user_id', $user->id)->firstOrFail();
                $this->action === 'hide_rating'
                    ? $records->hideRating($admin, $user, $rating, $this->reason)
                    : $records->restoreRating($admin, $user, $rating, $this->reason);

                return __('admin/actions.done.'.$this->action);
            })(),
            default => null,
        };

        $this->reset('action', 'reason', 'amount', 'target', 'messageTitle');
    }

    /** @return list<string> the tabs this staff member may open */
    private function tabs(): array
    {
        return array_values(array_filter(self::TABS, fn (string $tab) => $tab !== 'money' || $this->may(AdminPermission::PaymentView)));
    }

    private function member(): User
    {
        return User::query()->whereKey($this->userId)->firstOrFail();
    }

    private function admin(): AdminUser
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof AdminUser, 403);

        return $admin;
    }

    private function may(AdminPermission $permission): bool
    {
        return Auth::guard('admin')->user()?->can($permission->value) === true;
    }

    private function authorizeTo(AdminPermission $permission): void
    {
        abort_unless($this->may($permission), 403);
    }

    public function render()
    {
        $user = User::query()
            ->whereKey($this->userId)
            ->with([...self::detailRelations(), 'activeSuspension', 'driverProfile.vehicles'])
            ->firstOrFail();

        $standings = $this->may(AdminPermission::PaymentView) || $this->tab === 'overview'
            ? ['driver' => DebtStanding::of($user, DebtSide::Driver), 'passenger' => DebtStanding::of($user, DebtSide::Passenger)]
            : [];

        $data = match ($this->tab) {
            'passenger' => ['bookings' => Booking::query()->where('passenger_user_id', $user->id)->with(['scheduledTrip', 'driverProfile.user'])->latest('created_at')->limit(30)->get()],
            'driver' => ['commutes' => $user->driverProfile === null ? collect() : CommuteOffer::query()->where('driver_profile_id', $user->driverProfile->user_id)->with(['vehicle', 'locations'])->latest('created_at')->limit(20)->get()],
            'money' => ['entries' => DebtEntry::query()->where('user_id', $user->id)->latest('created_at')->latest('id')->limit(40)->get()],
            'reliability' => [
                'events' => ReliabilityEvent::query()->where('user_id', $user->id)->latest('occurred_at')->limit(30)->get(),
                'ratings' => Rating::query()->where('reviewed_user_id', $user->id)->withCount(['reports as open_reports_count' => fn ($q) => $q->whereIn('status', ['pending', 'under_review'])])->latest('created_at')->limit(20)->get(),
            ],
            'reports' => ['incidents' => Incident::query()->where(fn ($q) => $q->where('reported_user_id', $user->id)->orWhere('reporter_user_id', $user->id))->with(['reporter', 'reportedUser'])->latest('created_at')->limit(30)->get()],
            'notifications' => ['notifications' => Notification::query()->where('user_id', $user->id)->latest('created_at')->limit(40)->get()],
            'devices' => ['devices' => Device::query()->where('user_id', $user->id)->latest('last_seen_at')->get()],
            'notes' => ['activity' => AdminAction::query()->where('entity_id', $user->id)->orWhere('entity_id', $user->driverProfile?->user_id ?? '-')->with('admin')->latest('created_at')->limit(40)->get()],
            default => [],
        };

        return view('livewire.admin.member-profile', [
            'user' => $user,
            'tabs' => $this->tabs(),
            'standings' => $standings,
            'openDisputes' => Incident::query()->where('reporter_user_id', $user->id)->where('category', 'payment')->whereIn('status', IncidentStatus::openValues())->count(),
            'notes' => MemberNote::query()->where('user_id', $user->id)->with('admin')->latest('created_at')->latest('id')->limit($this->tab === 'notes' ? 100 : 3)->get(),
            'maySeeDocuments' => $this->maySeeDocuments(),
            'mayDecide' => $this->may(AdminPermission::MemberSuspend),
            'mayManageDebt' => $this->may(AdminPermission::DebtManage),
            'signInLocked' => ManageMemberAction::isSignInLocked($user),
            ...$data,
        ])->title((string) $user->full_name);
    }
}
