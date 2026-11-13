<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Safety\Enums\SosResolution;
use App\Domains\Safety\Models\SosEvent;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;

/**
 * SOS DETAIL (O02–O04): one alert — who raised it, where, the run it was on, the
 * emergency numbers, and its timeline (raised, cancelled by the member, picked up, closed,
 * and every staff step). Acknowledge and close are the safety desk's own actions (this
 * component IS SafetyCases), so their rules cannot differ between the two pages.
 */
#[Layout('components.layouts.admin')]
class SosDetail extends SafetyCases
{
    #[Locked]
    public string $sosId = '';

    public function mount(?SosEvent $sos = null): void
    {
        parent::mount();

        abort_if($sos === null, 404);
        $this->sosId = $sos->id;
    }

    public function render()
    {
        $sos = SosEvent::query()
            ->whereKey($this->sosId)
            ->with([
                'safetyEvent.user',
                'safetyEvent.tripSession.scheduledTrip.commuteOffer.vehicle',
                'safetyEvent.tripSession.scheduledTrip.commuteOffer.driverProfile.user',
                'safetyEvent.tripSession.scheduledTrip.commuteOffer.locations',
                'responderAdmin',
            ])
            ->firstOrFail();

        $steps = AdminAction::query()->where('entity_id', $sos->id)->with('admin')->orderBy('created_at')->orderBy('id')->get();

        // The timeline: what the member did, then what staff did, in time order.
        $timeline = collect([['at' => $sos->created_at, 'key' => 'raised', 'who' => $sos->safetyEvent?->user?->full_name, 'note' => null]]);

        if ($sos->cancelled_at) {
            $timeline->push(['at' => $sos->cancelled_at, 'key' => 'cancelled', 'who' => $sos->safetyEvent?->user?->full_name, 'note' => null]);
        }

        foreach ($steps as $step) {
            $timeline->push(['at' => $step->created_at, 'key' => $step->action, 'who' => $step->admin?->name, 'note' => $step->reason]);
        }

        return view('livewire.admin.sos-detail', [
            'sos' => $sos,
            'timeline' => $timeline->sortBy(fn ($item) => $item['at']?->getTimestamp())->values(),
            'open' => $sos->cancelled_at === null && $sos->resolution === null,
            'mayAct' => Auth::guard('admin')->user()?->can(AdminPermission::SafetyResolve->value) === true,
            'outcomes' => SosResolution::cases(),
        ])->title(__('admin/safety.detail_title', ['name' => (string) $sos->safetyEvent?->user?->full_name]));
    }
}
