<?php

namespace App\Domains\Safety\Actions;

use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Models\User;
use App\Domains\Safety\Enums\IncidentCategory;
use App\Domains\Safety\Enums\IncidentStatus;
use App\Domains\Safety\Enums\SafetyEventType;
use App\Domains\Safety\Enums\SafetySeverity;
use App\Domains\Safety\Models\Incident;
use App\Domains\Safety\Support\SafetyEventLog;
use App\Domains\Safety\Support\SafetySettings;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Reporting something that happened (Chapter 10's incident wizard, screen 32).
 *
 * 🔴 The severity is decided HERE, from the category, and never taken from the request. That is the
 * central decision in this class.
 *
 * A reporter cannot be asked to rate their own emergency. Somebody who has just been harassed is not
 * in a position to choose between "medium" and "high", and whichever they pick will be wrong in one
 * of two ways: understate it and the report waits behind a lost umbrella, overstate it and the
 * severity field stops meaning anything because every report is critical. Worse, letting the client
 * set it hands the queue's ordering to anybody who can send an HTTP request.
 *
 * So the mapping is a platform judgement, written down once: harassment and violence outrank a lost
 * item, and no phrasing in a description changes that.
 *
 * 🔒 The report is recorded even when it names nobody and attaches to nothing. Chapter 10's own
 * categories include "lost item" and "other", and a report about a journey that was never booked —
 * somebody impersonating a Rafeeq driver, say — is exactly the report the platform most needs to
 * receive. Requiring a booking id would refuse it.
 */
final readonly class ReportIncidentAction
{
    /**
     * How seriously each category is taken. A platform judgement, not the reporter's.
     *
     * `identity_mismatch` sits with the worst of them deliberately: "the person who arrived is not
     * the person in the app" is either a serious safety matter or an account being shared, and both
     * need looking at the same day.
     */
    private const array SEVERITY = [
        IncidentCategory::Harassment->value => SafetySeverity::Critical,
        IncidentCategory::IdentityMismatch->value => SafetySeverity::Critical,
        IncidentCategory::UnsafeDriving->value => SafetySeverity::High,
        IncidentCategory::NoShow->value => SafetySeverity::Low,
        IncidentCategory::Payment->value => SafetySeverity::Low,
        IncidentCategory::LostItem->value => SafetySeverity::Low,
        IncidentCategory::Other->value => SafetySeverity::Medium,
        /*
         * Feature 03 files X-7. Being put out of a car part-way, and a women-only seat used against
         * its purpose, are about somebody's safety and are read the same morning. A false arrival is
         * a dispute about a fee (TRIP-17), and an abusive review hurts but endangers nobody.
         */
        IncidentCategory::DroppedEarly->value => SafetySeverity::High,
        IncidentCategory::WomenOnlyMisuse->value => SafetySeverity::High,
        IncidentCategory::FalseArrival->value => SafetySeverity::Medium,
        IncidentCategory::AbusiveReview->value => SafetySeverity::Low,
    ];

    /**
     * How long the platform has to respond, per severity, in hours.
     *
     * Written on the row at creation (`sla_due_at`) rather than computed when somebody looks. A
     * deadline that is recalculated each time it is read can be quietly moved by changing a config
     * value, and a missed deadline is exactly the thing nobody should be able to move afterwards.
     */
    private const array SLA_HOURS = [
        SafetySeverity::Critical->value => 1,
        SafetySeverity::High->value => 4,
        SafetySeverity::Medium->value => 24,
        SafetySeverity::Low->value => 72,
    ];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(User $reporter, array $attributes): Incident
    {
        $category = IncidentCategory::from($attributes['category']);
        $severity = self::SEVERITY[$category->value];

        $booking = $this->bookingOf($reporter, $attributes['bookingId'] ?? null);

        if ($booking !== null) {
            $this->assertInsideTheWindow($booking);
        }

        return DB::transaction(function () use ($reporter, $attributes, $category, $severity, $booking): Incident {
            $incident = new Incident;

            $incident->fill([
                'booking_id' => $booking?->id,
                'trip_session_id' => $booking?->scheduledTrip?->tripSession?->id,
                'reporter_user_id' => $reporter->id,
                /*
                 * Who is being reported, when the reporter named somebody. Derived from the booking
                 * where possible rather than accepted from the request: a report that let the caller
                 * name any user id is a way to put a mark against a stranger.
                 */
                'reported_user_id' => $this->reportedUser($reporter, $booking),
                'category' => $category->value,
                'description' => $attributes['description'] ?? null,
            ]);

            // Not fillable: neither the severity nor the deadline is the reporter's to set.
            $incident->severity = $severity->value;
            $incident->status = IncidentStatus::Open->value;
            $incident->sla_due_at = now()->addHours(self::SLA_HOURS[$severity->value]);

            $incident->save();

            /*
             * 🔒 And into the table nobody may delete. A report can be resolved, closed, or found
             * baseless — and the fact that it was made still happened. `incidents` is a case file
             * that changes; `safety_events` is the record that it exists.
             */
            SafetyEventLog::record(
                type: SafetyEventType::IncidentCreated,
                user: $reporter,
                severity: $severity,
                tripSession: $booking?->scheduledTrip?->tripSession,
                booking: $booking,
                metadata: [
                    'incidentId' => $incident->id,
                    'category' => $category->value,
                    // Deliberately NOT the description: see SafetyEventLog on what belongs in a
                    // table that is never deleted.
                    'hasDescription' => ($attributes['description'] ?? null) !== null,
                ],
            );

            return $incident;
        });
    }

    /**
     * The booking the report is about, if the reporter named one they actually hold.
     *
     * 🔒 Scoped to their own bookings: a report that accepted any booking id would let somebody
     * attach a fabricated complaint to a journey between two strangers.
     */
    private function bookingOf(User $reporter, ?string $bookingId): ?Booking
    {
        if ($bookingId === null) {
            return null;
        }

        return Booking::query()
            ->whereKey($bookingId)
            ->where(function ($query) use ($reporter): void {
                $query
                    ->where('passenger_user_id', $reporter->id)
                    ->orWhere('driver_profile_id', $reporter->id);
            })
            ->with('scheduledTrip.tripSession')
            ->first()
            ?? throw DomainException::of(ErrorCode::IncidentNotReportable, fields: [
                'bookingId' => ['NOT_YOURS'],
            ]);
    }

    /**
     * 🔴 A problem with a journey is reported within 24 hours of it (SAFE-08, Feature 03 files X-7).
     *
     * Measured from the END of the journey: when the run finished, or — for a morning that never
     * ran (the driver never came, the day was called off) — from when it should have left. A
     * journey that has not ended yet, or has not happened yet, is always inside the window: a
     * driver's threatening message the night before is reportable before the trip, not after.
     *
     * Only a report tied to a booking has a journey to measure from. One about somebody posing as
     * a Rafeeq driver has none and is always accepted — see the class docblock.
     *
     * ⚠️ The 24 hours are the time to REPORT. The appeal window is a different number (SAFE-11).
     */
    private function assertInsideTheWindow(Booking $booking): void
    {
        $trip = $booking->scheduledTrip;
        $session = $trip?->tripSession;

        $endedAt = $session?->completed_at;

        if ($endedAt === null && $session?->started_at === null && $trip?->departure_at?->isPast()) {
            $endedAt = $trip->departure_at;
        }

        if ($endedAt === null) {
            return;
        }

        $closesAt = $endedAt->copy()->addHours(SafetySettings::reportWindowHours());

        if (now()->greaterThan($closesAt)) {
            throw DomainException::of(ErrorCode::ReportWindowClosed, fields: [
                'bookingId' => ['WINDOW_CLOSED'],
            ]);
        }
    }

    /**
     * The other party on that booking — the driver if a passenger is reporting, the passenger if a
     * driver is.
     */
    private function reportedUser(User $reporter, ?Booking $booking): ?string
    {
        if ($booking === null) {
            return null;
        }

        return $booking->passenger_user_id === $reporter->id
            ? $booking->driver_profile_id
            : $booking->passenger_user_id;
    }
}
