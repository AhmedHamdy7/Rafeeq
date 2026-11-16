<?php

namespace App\Livewire\Admin\Directory;

use App\Domains\Admin\Actions\StaffPauseCommuteAction;
use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Commute\Actions\ChangeCommuteStatusAction;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\CommutePausedReason;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;

/**
 * COMMUTE DETAIL (C02, C03): the route, the schedule, the price, the riders and the days ahead.
 *
 * Two staff actions: the end of a gender review (COM-45), resuming a women-only commute paused
 * because its driver's gender changed; and pausing a commute from the dashboard (C04), with a
 * message the driver reads, which only staff can then resume.
 */
#[Layout('components.layouts.admin')]
class CommuteDetail extends RecordPage
{
    #[Locked]
    public string $commuteId = '';

    public bool $resuming = false;

    public bool $pausing = false;

    /** C04 · what the driver reads on the paused commute and in her inbox. */
    public string $pauseMessage = '';

    public function mount(CommuteOffer $commute): void
    {
        $this->authorizeTo(AdminPermission::CommuteView);
        $this->commuteId = $commute->id;
    }

    public function startResume(): void
    {
        $this->authorizeTo(AdminPermission::CommuteModerate);
        $this->resetValidation();
        $this->reset('reason', 'done');
        $this->resuming = true;
    }

    public function startPause(): void
    {
        $this->authorizeTo(AdminPermission::CommuteModerate);
        $this->resetValidation();
        $this->reset('reason', 'done', 'pauseMessage', 'resuming');
        $this->pausing = true;
    }

    public function pause(StaffPauseCommuteAction $pauses): void
    {
        $this->authorizeTo(AdminPermission::CommuteModerate);
        $this->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
            'pauseMessage' => ['required', 'string', 'min:10', 'max:500'],
        ], attributes: ['reason' => __('admin/actions.reason'), 'pauseMessage' => __('admin/records.commute.pause_message')]);

        $offer = CommuteOffer::query()->whereKey($this->commuteId)->with('driverProfile.user')->firstOrFail();

        try {
            $pauses->pause($this->admin(), $offer, $this->pauseMessage, $this->reason);
        } catch (DomainException $e) {
            $this->addError('record', __('errors.'.$e->errorCode->value));

            return;
        }

        $this->reset('pausing', 'reason', 'pauseMessage');
        $this->done = __('admin/records.commute.paused_done');
    }

    public function resume(ChangeCommuteStatusAction $commutes, StaffPauseCommuteAction $pauses): void
    {
        $this->authorizeTo(AdminPermission::CommuteModerate);
        $this->requireReason();

        $offer = CommuteOffer::query()->whereKey($this->commuteId)->firstOrFail();

        if ($offer->status === CommuteOfferStatus::Paused && $offer->paused_reason === CommutePausedReason::ByStaff) {
            try {
                $pauses->resume($this->admin(), $offer, $this->reason);
            } catch (DomainException $e) {
                $this->addError('record', __('errors.'.$e->errorCode->value));

                return;
            }

            $this->reset('resuming', 'reason');
            $this->done = __('admin/records.commute.resumed');

            return;
        }

        abort_unless($offer->status === CommuteOfferStatus::Paused && $offer->paused_reason === CommutePausedReason::GenderReview, 409);

        try {
            DB::transaction(function () use ($commutes, $offer): void {
                $commutes->resume($offer);
                AdminActionLog::record($this->admin(), 'commute.resume_after_review', $offer, ['status' => 'paused', 'paused_reason' => 'gender_review'], ['status' => 'published'], $this->reason);
            });
        } catch (DomainException $e) {
            // The commonest refusal: the driver is no longer a woman, so a women-only commute
            // cannot run. That is the review's answer, and staff read it rather than a blank page.
            $this->addError('record', __('errors.'.$e->errorCode->value));

            return;
        }

        $this->reset('resuming', 'reason');
        $this->done = __('admin/records.commute.resumed');
    }

    public function render()
    {
        $commute = CommuteOffer::query()
            ->whereKey($this->commuteId)
            ->with(['driverProfile.user', 'vehicle', 'locations', 'schedule', 'rules'])
            ->withCount([
                'seatRequests as waiting' => fn ($q) => $q->where('status', SeatRequestStatus::Waitlisted->value),
                'seatRequests as pending' => fn ($q) => $q->where('status', SeatRequestStatus::Pending->value),
            ])
            ->firstOrFail();

        return view('livewire.admin.directory.commute-detail', [
            'commute' => $commute,
            'days' => ScheduledTrip::query()->where('commute_offer_id', $commute->id)->where('trip_date', '>=', now('Africa/Cairo')->subDays(7)->toDateString())->orderBy('trip_date')->limit(21)->get(),
            'history' => $this->staffActions($commute->id),
            'mayResume' => $this->may(AdminPermission::CommuteModerate) && $commute->status === CommuteOfferStatus::Paused && in_array($commute->paused_reason, [CommutePausedReason::GenderReview, CommutePausedReason::ByStaff], true),
            'mayPause' => $this->may(AdminPermission::CommuteModerate) && $commute->status === CommuteOfferStatus::Published,
            'pausePreview' => $this->pausing ? StaffPauseCommuteAction::preview($commute) : null,
        ])->title(__('admin/records.commute.title'));
    }
}
