<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Actions\SuspendMemberAction;
use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\MemberDirectory;
use App\Domains\Identity\Enums\SuspensionReason;
use App\Domains\Identity\Models\User;
use App\Domains\Safety\Enums\IncidentStatus;
use App\Domains\Safety\Models\Incident;
use App\Domains\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The MEMBERS section: find somebody, see where they stand, put their account on hold or
 * lift it.
 *
 * `member.view` reads; `member.suspend` acts, re-checked inside each action for the usual
 * reason — a Livewire action is a POST the browser can make whatever the page rendered.
 *
 * The suspend form shows, before the button, how many booked days a hold on a driver
 * leaves stranded. The hold does not cancel them (see SuspendMemberAction), and a decision
 * whose biggest consequence is invisible at the moment it is taken is not a decision the
 * person taking it actually made.
 */
#[Layout('components.layouts.admin')]
class Members extends Component
{
    use WithPagination;

    #[Url(as: 'tab')]
    public string $tab = 'all';

    #[Url(as: 'q')]
    public string $search = '';

    /** The member whose profile is open — linkable, so the safety desk can send somebody here. */
    #[Url(as: 'member')]
    public ?string $openId = null;

    /** 'suspend' or 'reinstate' while a form is open. */
    public string $mode = '';

    public string $reasonCode = '';

    public string $note = '';

    public string $incidentId = '';

    public function mount(): void
    {
        $this->authorizeTo(AdminPermission::MemberView);

        if (! in_array($this->tab, MemberDirectory::TABS, true)) {
            $this->tab = 'all';
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function pick(string $tab): void
    {
        $this->tab = in_array($tab, MemberDirectory::TABS, true) ? $tab : 'all';
        $this->resetPage();
    }

    public function open(?string $id): void
    {
        $this->openId = $this->openId === $id ? null : $id;
        $this->closeForm();
    }

    public function startForm(string $mode): void
    {
        $this->authorizeTo(AdminPermission::MemberSuspend);

        $this->closeForm();
        $this->mode = in_array($mode, ['suspend', 'reinstate'], true) ? $mode : '';
    }

    public function suspend(SuspendMemberAction $action): void
    {
        $this->authorizeTo(AdminPermission::MemberSuspend);

        $this->validate([
            'reasonCode' => ['required', 'string', Rule::enum(SuspensionReason::class)],
            // 255: the column. It is the file note — internal, never shown to the member.
            'note' => ['required', 'string', 'min:10', 'max:255'],
            'incidentId' => ['nullable', 'string'],
        ], attributes: [
            'reasonCode' => __('admin.members.reason'),
            'note' => __('admin.members.note'),
        ]);

        $member = $this->member();
        $incident = $this->incidentId === ''
            ? null
            : Incident::query()->whereKey($this->incidentId)->first() ?? abort(404);

        try {
            $suspension = $action->suspend($member, $this->admin(), SuspensionReason::from($this->reasonCode), $this->note, $incident);
        } catch (DomainException $e) {
            $this->addError('note', __('errors.'.$e->errorCode->value));

            return;
        }

        $this->closeForm();

        session()->flash('status', __('admin.members.suspended', [
            'name' => $member->full_name,
            'case' => $suspension->case_number,
        ]));
    }

    public function reinstate(SuspendMemberAction $action): void
    {
        $this->authorizeTo(AdminPermission::MemberSuspend);

        $this->validate([
            'note' => ['required', 'string', 'min:10', 'max:255'],
        ], attributes: ['note' => __('admin.members.note')]);

        $member = $this->member();

        try {
            $action->reinstate($member, $this->admin(), $this->note);
        } catch (DomainException $e) {
            $this->addError('note', __('errors.'.$e->errorCode->value));

            return;
        }

        $this->closeForm();

        session()->flash('status', __('admin.members.reinstated', ['name' => $member->full_name]));
    }

    private function closeForm(): void
    {
        $this->resetValidation();
        $this->reset('mode', 'reasonCode', 'note', 'incidentId');
    }

    private function member(): User
    {
        return User::query()->whereKey($this->openId)->first() ?? abort(404);
    }

    private function admin(): ?AdminUser
    {
        return Auth::guard('admin')->user();
    }

    private function authorizeTo(AdminPermission $permission): void
    {
        abort_unless($this->admin()?->can($permission->value) === true, 403);
    }

    public function render()
    {
        $opened = $this->openId === null ? null : User::query()
            ->whereKey($this->openId)
            ->with([
                'driverProfile',
                'stats',
                'activeSuspension',
                'suspensions' => fn ($query) => $query->with('suspendedBy', 'liftedBy')->latest('suspended_at'),
            ])
            ->first();

        return view('livewire.admin.members', [
            'members' => MemberDirectory::page($this->tab, $this->search, 20),
            'opened' => $opened,
            'openReports' => $opened === null ? collect() : Incident::query()
                ->where('reported_user_id', $opened->id)
                ->whereIn('status', [IncidentStatus::Open->value, IncidentStatus::UnderReview->value, IncidentStatus::Escalated->value])
                ->orderBy('sla_due_at')
                ->orderBy('id')
                ->get(),
            'strandedDays' => $opened === null ? 0 : MemberDirectory::bookedDaysAsDriver($opened),
            'reasons' => SuspensionReason::cases(),
            'mayAct' => $this->admin()?->can(AdminPermission::MemberSuspend->value) === true,
            'maySeeSafety' => $this->admin()?->can(AdminPermission::SafetyView->value) === true,
        ])->title(__('admin.members.title'));
    }
}
