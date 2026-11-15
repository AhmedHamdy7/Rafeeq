<?php

namespace App\Domains\Admin\Actions;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Booking\Enums\BookingActorType;
use App\Domains\Booking\Enums\BookingEventType;
use App\Domains\Booking\Enums\PaymentStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Support\BookingEventLog;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Reliability\Actions\AppealReliabilityEventAction;
use App\Domains\Reliability\Enums\ReliabilityEventKind;
use App\Domains\Reliability\Models\ReliabilityEvent;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Trip\Enums\AttendanceStatus;
use App\Domains\Trip\Enums\DisputeResolution;
use App\Domains\Trip\Enums\WarningKind;
use App\Domains\Trip\Models\Attendance;
use App\Domains\Trip\Models\MemberWarning;
use Illuminate\Support\Facades\DB;

final readonly class ResolveAttendanceDisputeAction
{
    public function __construct(private AppealReliabilityEventAction $reliability) {}

    public function execute(Booking $booking, AdminUser $admin, DisputeResolution $resolution, ?string $note = null, ?string $reason = null): Attendance
    {
        $note = trim((string) $note) === '' ? null : mb_substr(trim((string) $note), 0, 255);
        $reason = trim((string) $reason) === '' ? ($note ?? 'No-show dispute decided.') : trim((string) $reason);

        return DB::transaction(function () use ($booking, $admin, $resolution, $note, $reason): Attendance {
            $attendance = Attendance::query()->whereKey($booking->getKey())->lockForUpdate()->first();

            if ($attendance === null
                || $attendance->status !== AttendanceStatus::PassengerNoShow
                || $attendance->disputed_at === null
                || $attendance->dispute_resolution !== null) {
                throw DomainException::of(ErrorCode::AttendanceDisputeNotOpen);
            }

            $booking = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();
            $paymentBefore = $booking->payment_status;

            $attendance->forceFill([
                'dispute_resolution' => $resolution,
                'dispute_resolved_by' => $admin->getKey(),
                'dispute_resolved_at' => now(),
                'dispute_note' => $note,
            ])->save();

            $booking->forceFill([
                'payment_status' => $resolution === DisputeResolution::Refunded
                    ? PaymentStatus::Refunded->value
                    : PaymentStatus::NotDue->value,
            ])->save();

            if ($resolution !== DisputeResolution::Upheld) {
                $this->removeNoShow($booking, $admin, $reason);
            }

            BookingEventLog::record(
                $booking,
                BookingEventType::DisputeDecided,
                BookingActorType::Admin,
                (string) $admin->getKey(),
                metadata: ['resolution' => $resolution->value],
            );

            AdminActionLog::record($admin, 'attendance.dispute_decide', $attendance,
                before: ['payment_status' => $paymentBefore->value],
                after: ['dispute_resolution' => $resolution->value, 'payment_status' => $booking->payment_status->value],
                reason: $reason,
            );

            $booking->loadMissing('passenger');

            if ($booking->passenger !== null) {
                Notifier::send($booking->passenger, NotificationType::NoShowDisputeDecided,
                    params: ['date' => $booking->loadMissing('scheduledTrip')->scheduledTrip?->trip_date?->toFormattedDateString() ?? ''],
                    data: [
                        'bookingId' => (string) $booking->getKey(),
                        'tripId' => (string) $booking->scheduled_trip_id,
                        'resolution' => $resolution->value,
                    ],
                );
            }

            return $attendance;
        });
    }

    private function removeNoShow(Booking $booking, AdminUser $admin, string $reason): void
    {
        MemberWarning::query()
            ->where('user_id', $booking->passenger_user_id)
            ->where('kind', WarningKind::NoShow->value)
            ->where('booking_id', $booking->getKey())
            ->lockForUpdate()
            ->get()
            ->each(function (MemberWarning $warning) use ($admin): void {
                if ($warning->dispute_resolution === DisputeResolution::Overturned) {
                    return;
                }

                $warning->forceFill([
                    'dispute_resolution' => DisputeResolution::Overturned,
                    'dispute_resolved_by' => $admin->getKey(),
                    'dispute_resolved_at' => now(),
                ])->save();

                ResolveFeeAppealAction::removeFee($warning);
            });

        ReliabilityEvent::query()
            ->where('user_id', $booking->passenger_user_id)
            ->where('kind', ReliabilityEventKind::PassengerNoShow->value)
            ->where('booking_id', $booking->getKey())
            ->whereNull('reversed_at')
            ->get()
            ->each(fn (ReliabilityEvent $event) => $this->reliability->approve($admin, $event, $reason, reverseFee: false, notify: false));
    }
}
