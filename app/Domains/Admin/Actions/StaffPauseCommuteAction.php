<?php

namespace App\Domains\Admin\Actions;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Booking\Actions\DisplaceConfirmedBookingsAction;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Actions\ChangeCommuteStatusAction;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Enums\CommutePausedReason;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * STAFF PAUSE A COMMUTE (C04): stop a commute from the dashboard, with a reason for the team and
 * a message for the driver.
 *
 * It is the pause the automatic reasons already use: every upcoming confirmed seat is cancelled
 * with no fee for anybody, the passengers are told and go back on the waiting list, and nothing
 * is restored on resume. What is new is who decides, the `by_staff` reason the app shows as
 * "Paused by RAFEEQ", and that only staff can resume it.
 *
 * Not while a run is under way: the people in the car are not displaced by a dashboard click.
 */
final readonly class StaffPauseCommuteAction
{
    public function __construct(private ChangeCommuteStatusAction $commutes) {}

    public function pause(AdminUser $by, CommuteOffer $offer, string $message, string $reason): CommuteOffer
    {
        $this->authorize($by);

        if ($offer->status !== CommuteOfferStatus::Published) {
            throw ValidationException::withMessages(['record' => __('admin/records.commute.pause_not_published')]);
        }

        if (self::liveRun($offer)) {
            throw ValidationException::withMessages(['record' => __('admin/records.commute.pause_live_run')]);
        }

        $message = trim($message);
        $driver = $offer->driverProfile?->user;

        return DB::transaction(function () use ($by, $offer, $message, $reason, $driver): CommuteOffer {
            $offer = $this->commutes->pause($offer, CommutePausedReason::ByStaff, $message);

            if ($driver !== null) {
                $locale = $driver->preferred_language === 'en' ? 'en' : 'ar';
                Notifier::send($driver, NotificationType::StaffMessage, params: [
                    'title' => __('admin/records.commute.paused_notice_title', locale: $locale),
                    'body' => $message,
                ], quiet: true);
            }

            AdminActionLog::record($by, 'commute.pause_by_staff', $offer, ['status' => 'published'], ['status' => 'paused', 'paused_reason' => CommutePausedReason::ByStaff->value, 'message' => $message], trim($reason));

            return $offer;
        });
    }

    public function resume(AdminUser $by, CommuteOffer $offer, string $reason): CommuteOffer
    {
        $this->authorize($by);

        abort_unless($offer->status === CommuteOfferStatus::Paused && $offer->paused_reason === CommutePausedReason::ByStaff, 409);

        return DB::transaction(function () use ($by, $offer, $reason): CommuteOffer {
            $offer = $this->commutes->resume($offer);
            AdminActionLog::record($by, 'commute.resume_by_staff', $offer, ['status' => 'paused', 'paused_reason' => CommutePausedReason::ByStaff->value], ['status' => 'published'], trim($reason));

            return $offer;
        });
    }

    /**
     * What pausing would do now (C04's impact preview).
     *
     * @return array{seats: int, passengers: int, days: int, waiting: int}
     */
    public static function preview(CommuteOffer $offer): array
    {
        $bookings = DisplaceConfirmedBookingsAction::futureConfirmedBookings($offer);

        return [
            'seats' => $bookings->count(),
            'passengers' => $bookings->pluck('passenger_user_id')->unique()->count(),
            'days' => $bookings->pluck('scheduled_trip_id')->unique()->count(),
            'waiting' => SeatRequest::query()->where('commute_offer_id', $offer->id)->where('status', SeatRequestStatus::Waitlisted->value)->count(),
        ];
    }

    public static function liveRun(CommuteOffer $offer): bool
    {
        return ScheduledTrip::query()
            ->where('commute_offer_id', $offer->id)
            ->whereIn('status', [ScheduledTripStatus::Preparing->value, ScheduledTripStatus::EnRoute->value, ScheduledTripStatus::InProgress->value])
            ->exists();
    }

    private function authorize(AdminUser $by): void
    {
        if (! $by->can(AdminPermission::CommuteModerate->value)) {
            throw new AuthorizationException;
        }
    }
}
