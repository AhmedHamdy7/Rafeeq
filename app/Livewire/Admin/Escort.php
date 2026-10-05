<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Actions\ArmEscortAction;
use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Geo\Models\Corridor;
use App\Domains\Safety\Support\EscortCoverage;
use App\Domains\Safety\Support\SafetySettings;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * NIGHT ESCORT: which corridors the desk is watching, and the switch to watch one more.
 *
 * Read with `safety.view`; arming and standing down need `safety.resolve` and are re-checked inside
 * each action (see SafetyCases on why a rendered button is not a permission).
 */
#[Layout('components.layouts.admin')]
class Escort extends Component
{
    use WithPagination;

    /** The corridor whose arm/stand-down form is open. */
    public ?string $openCorridor = null;

    public string $mode = '';

    public int $hours = 4;

    public string $reason = '';

    public function mount(): void
    {
        $this->authorizeTo(AdminPermission::SafetyView);
    }

    public function open(string $corridorId, string $mode): void
    {
        $this->resetValidation();
        $this->openCorridor = $corridorId;
        $this->mode = $mode === 'disarm' ? 'disarm' : 'arm';
        $this->reason = '';
    }

    public function arm(string $corridorId, ArmEscortAction $action): void
    {
        $this->authorizeTo(AdminPermission::SafetyResolve);

        $this->validate([
            'hours' => ['required', 'integer', 'between:1,'.ArmEscortAction::MAX_MANUAL_HOURS],
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ], attributes: [
            'hours' => __('admin.escort.hours'),
            'reason' => __('admin.escort.reason'),
        ]);

        $corridor = Corridor::query()->whereKey($corridorId)->first() ?? abort(404);

        try {
            $action->arm($corridor, $this->admin(), $this->hours, $this->reason);
        } catch (DomainException $e) {
            $this->addError('reason', __('errors.'.$e->errorCode->value));

            return;
        }

        $this->reset('openCorridor', 'mode', 'reason');

        session()->flash('status', __('admin.escort.armed'));
    }

    public function disarm(string $corridorId, ArmEscortAction $action): void
    {
        $this->authorizeTo(AdminPermission::SafetyResolve);

        $this->validate(['reason' => ['required', 'string', 'min:10', 'max:255']], attributes: [
            'reason' => __('admin.escort.reason'),
        ]);

        // Already over — it ran out, or a colleague stood it down while this page was open.
        $window = EscortCoverage::activeFor($corridorId);

        if ($window === null) {
            $this->addError('reason', __('errors.'.ErrorCode::EscortNotActive->value));

            return;
        }

        try {
            $action->disarm($window, $this->admin(), $this->reason);
        } catch (DomainException $e) {
            $this->addError('reason', __('errors.'.$e->errorCode->value));

            return;
        }

        $this->reset('openCorridor', 'mode', 'reason');

        session()->flash('status', __('admin.escort.disarmed'));
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
        $corridors = Corridor::query()->orderBy('name')->orderBy('id')->paginate(25);

        $windows = EscortCoverage::active()
            ->whereIn('corridor_id', $corridors->getCollection()->pluck('id'))
            ->with('armedBy')
            ->get()
            ->keyBy('corridor_id');

        [$tonightStarts, $tonightEnds] = EscortCoverage::tonight();

        return view('livewire.admin.escort', [
            'corridors' => $corridors,
            'windows' => $windows,
            'armedCount' => EscortCoverage::armedCount(),
            'tripsCovered' => EscortCoverage::tripsCoveredTonight(),
            'autoEnabled' => SafetySettings::nightEscortEnabled(),
            'tonightStarts' => $tonightStarts,
            'tonightEnds' => $tonightEnds,
            'maxHours' => ArmEscortAction::MAX_MANUAL_HOURS,
        ])->title(__('admin.escort.title'));
    }
}
