<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Actions\HandleIncidentAction;
use App\Domains\Admin\Actions\RespondToSosAction;
use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\SafetyCaseQueue;
use App\Domains\Safety\Enums\SosResolution;
use App\Domains\Safety\Models\Incident;
use App\Domains\Safety\Models\SosEvent;
use App\Domains\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The SAFETY CASES section: live alerts on top, open reports below.
 *
 * The two are on one page because they are one desk. An SOS and a harassment report about
 * the same driver an hour apart are the same story, and splitting them across two screens
 * is how the person reading one never sees the other.
 *
 * 🔴 The page polls (see the view). Every other dashboard page can afford to show a
 * snapshot; this one cannot — an alert raised while an operator is reading a report must
 * appear without them thinking to reload, and the rail badge alone is not enough because
 * the rail is only redrawn on navigation.
 *
 * Permissions follow the verification queue's shape: `safety.view` to read, and
 * `safety.resolve` re-checked inside every action, because a Livewire action is a POST the
 * browser can make directly whatever the page chose to render.
 */
#[Layout('components.layouts.admin')]
class SafetyCases extends Component
{
    use WithPagination;

    /** The alert whose "how did it end" form is open. */
    public ?string $openAlert = null;

    public string $resolution = '';

    public string $note = '';

    /** The report whose decision form is open, and which decision. */
    public ?string $openReport = null;

    public string $step = '';

    public string $reply = '';

    public function mount(): void
    {
        $this->authorizeTo(AdminPermission::SafetyView);
    }

    public function acknowledge(string $id, RespondToSosAction $action): void
    {
        $this->authorizeTo(AdminPermission::SafetyResolve);

        try {
            $action->acknowledge($this->alert($id), $this->admin());
        } catch (DomainException $e) {
            $this->addError('alerts', __('errors.'.$e->errorCode->value));

            return;
        }

        session()->flash('status', __('admin.safety.acknowledged'));
    }

    public function resolveAlert(string $id, RespondToSosAction $action): void
    {
        $this->authorizeTo(AdminPermission::SafetyResolve);

        $this->validate([
            'resolution' => ['required', 'string', Rule::enum(SosResolution::class)],
            'note' => ['required', 'string', 'min:10', 'max:255'],
        ], attributes: [
            'resolution' => __('admin.safety.outcome'),
            'note' => __('admin.safety.note'),
        ]);

        try {
            $action->resolve($this->alert($id), $this->admin(), SosResolution::from($this->resolution), $this->note);
        } catch (DomainException $e) {
            $this->addError('note', __('errors.'.$e->errorCode->value));

            return;
        }

        $this->reset('openAlert', 'resolution', 'note');

        session()->flash('status', __('admin.safety.alert_closed'));
    }

    public function take(string $id, HandleIncidentAction $action): void
    {
        $this->authorizeTo(AdminPermission::SafetyResolve);

        try {
            $action->take($this->report($id), $this->admin());
        } catch (DomainException $e) {
            $this->addError('reports', __('errors.'.$e->errorCode->value));

            return;
        }

        session()->flash('status', __('admin.safety.taken'));
    }

    public function decide(string $id, HandleIncidentAction $action): void
    {
        $this->authorizeTo(AdminPermission::SafetyResolve);

        $this->validate([
            'step' => ['required', 'string', Rule::in(['escalate', 'resolve', 'close'])],
            // 255: `incidents.resolution` and `admin_actions.reason` are both that wide, and
            // a message cut off mid-sentence in front of the reporter is worse than a limit.
            'reply' => ['required', 'string', 'min:10', 'max:255'],
        ], attributes: ['reply' => __('admin.safety.message')]);

        $report = $this->report($id);
        $admin = $this->admin();

        try {
            match ($this->step) {
                'escalate' => $action->escalate($report, $admin, $this->reply),
                'resolve' => $action->resolve($report, $admin, $this->reply),
                'close' => $action->close($report, $admin, $this->reply),
            };
        } catch (DomainException $e) {
            $this->addError('reply', __('errors.'.$e->errorCode->value));

            return;
        }

        $done = $this->step;

        $this->reset('openReport', 'step', 'reply');

        session()->flash('status', __('admin.safety.decided_'.$done));

        $this->keepThePageInRange();
    }

    public function openDecision(string $id, string $step): void
    {
        $this->resetValidation();
        $this->openReport = $id;
        $this->step = $step;
        $this->reply = '';
    }

    /**
     * 404 for an id that does not exist, rather than a DomainException — see the note in
     * VerificationQueue::pending(). Whether it is still actionable is the Action's question.
     */
    private function alert(string $id): SosEvent
    {
        return SosEvent::query()->whereKey($id)->first() ?? abort(404);
    }

    private function report(string $id): Incident
    {
        return Incident::query()->whereKey($id)->first() ?? abort(404);
    }

    private function admin(): ?AdminUser
    {
        return Auth::guard('admin')->user();
    }

    private function authorizeTo(AdminPermission $permission): void
    {
        abort_unless($this->admin()?->can($permission->value) === true, 403);
    }

    private function keepThePageInRange(): void
    {
        $page = SafetyCaseQueue::openReports($this->perPage());

        if ($page->currentPage() > $page->lastPage()) {
            $this->setPage($page->lastPage());
        }
    }

    private function perPage(): int
    {
        return 10;
    }

    public function render()
    {
        return view('livewire.admin.safety-cases', [
            'alerts' => SafetyCaseQueue::liveAlerts(),
            'reports' => SafetyCaseQueue::openReports($this->perPage()),
            'mayAct' => $this->admin()?->can(AdminPermission::SafetyResolve->value) === true,
            'outcomes' => SosResolution::cases(),
        ])->title(__('admin.safety.title'));
    }
}
