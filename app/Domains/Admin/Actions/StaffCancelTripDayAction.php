<?php

namespace App\Domains\Admin\Actions;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Actions\CancelTripDayAction;
use App\Domains\Commute\Enums\ScheduledTripStatus;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class StaffCancelTripDayAction
{
    public function __construct(private CancelTripDayAction $days) {}

    public function cancel(AdminUser $by, ScheduledTrip $trip, string $reason): ScheduledTrip
    {
        if (! $by->can(AdminPermission::CommuteModerate->value)) {
            throw new AuthorizationException;
        }

        if ($trip->status !== ScheduledTripStatus::Scheduled) {
            throw ValidationException::withMessages(['record' => __('admin/records.trip.cancel_not_scheduled')]);
        }

        $reason = trim($reason);
        $driver = $trip->commuteOffer?->driverProfile?->user;

        return DB::transaction(function () use ($by, $trip, $reason, $driver): ScheduledTrip {
            $before = ['status' => $trip->status->value, 'passengers' => self::passengers($trip)];

            $cancelled = $this->days->byPlatform($trip, $reason);

            if ($driver !== null) {
                $locale = $driver->preferred_language === 'en' ? 'en' : 'ar';
                Notifier::send($driver, NotificationType::StaffMessage, params: [
                    'title' => __('admin/records.trip.cancelled_notice_title', ['date' => $trip->trip_date->format('j/n')], $locale),
                    'body' => __('admin/records.trip.cancelled_notice_body', ['date' => $trip->trip_date->format('j/n')], $locale),
                ], quiet: true);
            }

            AdminActionLog::record($by, 'trip.cancel_by_staff', $cancelled, $before, ['status' => 'cancelled', 'cancelled_by' => 'platform'], $reason);

            return $cancelled;
        });
    }

    public static function passengers(ScheduledTrip $trip): int
    {
        return Booking::query()
            ->where('scheduled_trip_id', $trip->id)
            ->whereIn('status', [BookingStatus::Pending->value, BookingStatus::Confirmed->value])
            ->distinct()
            ->count('passenger_user_id');
    }
}
