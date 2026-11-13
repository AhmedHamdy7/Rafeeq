<?php

namespace App\Livewire\Admin\Directory;

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
 * The one staff action here is the end of a gender review (COM-45): a women-only commute that
 * was paused because its driver's gender changed is resumed once somebody has checked the
 * change. Pausing for any other reason stays with the driver or with the rules that do it.
 */
#[Layout('components.layouts.admin')]
class CommuteDetail extends RecordPage
{
    #[Locked]
    public string $commuteId = '';

    public bool $resuming = false;

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

    public function resume(ChangeCommuteStatusAction $commutes): void
    {
        $this->authorizeTo(AdminPermission::CommuteModerate);
        $this->requireReason();

        $offer = CommuteOffer::query()->whereKey($this->commuteId)->firstOrFail();
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
            'mayResume' => $this->may(AdminPermission::CommuteModerate) && $commute->status === CommuteOfferStatus::Paused && $commute->paused_reason === CommutePausedReason::GenderReview,
        ])->title(__('admin/records.commute.title'));
    }
}
