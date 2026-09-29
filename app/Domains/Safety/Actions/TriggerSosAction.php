<?php

namespace App\Domains\Safety\Actions;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Identity\Models\User;
use App\Domains\Safety\Enums\SafetyEventType;
use App\Domains\Safety\Enums\SafetySeverity;
use App\Domains\Safety\Models\SosEvent;
use App\Domains\Safety\Support\SafetyEventLog;
use App\Domains\Safety\Support\SafetySettings;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Support\Facades\DB;

/**
 * The SOS button (Chapter 10, screens 16 and 44).
 *
 * 🔴 This is the only Action in the codebase that must work when everything else is broken, and
 * every decision in it follows from that.
 *
 * **It is recorded before it is confirmed.** The countdown that guards against an accidental tap
 * runs on the PHONE, and the row is written the moment the button is pressed — not when the
 * countdown finishes. That is the opposite of how a "confirm first" flow is normally built, and it
 * is deliberate: if the phone is taken, thrown, or its battery dies during those ten seconds, a
 * design that waited for confirmation would have no record that anything happened at all. A false
 * alarm cancelled a moment later costs one row and one line in a dashboard. The other way round
 * costs somebody an emergency nobody heard about.
 *
 * **It never refuses for a reason the person cannot act on.** No verification gate, no complete
 * profile, no active trip required. Somebody in trouble at a roadside is not going to finish
 * uploading a national ID first, and a 403 at that moment is the platform's worst possible answer.
 * The only thing that can fail here is the database.
 *
 * **A discreet alert makes no sound and no vibration**, which is a client responsibility this
 * server cannot enforce — but the flag is recorded, so a dashboard reading `is_discreet` knows not
 * to call back and ask "are you all right?" out loud in a car with somebody still in it.
 *
 * What is NOT here: notifying the guardians (Phase 12), the ops response queue (Phase 13), and any
 * integration with emergency services (the chapter itself calls that a future version). What
 * exists now is the record those three will read, and the record is the part that cannot be
 * reconstructed after the fact.
 */
final readonly class TriggerSosAction
{
    public function execute(
        User $user,
        bool $isDiscreet = false,
        ?Coordinate $at = null,
        ?TripSession $session = null,
    ): SosEvent {
        /*
         * The trip is looked up rather than taken from the request when the caller did not name
         * one: somebody pressing this button has better things to do than tell us which journey
         * they are on, and the answer is knowable.
         */
        $session ??= $this->currentTripOf($user);

        return DB::transaction(function () use ($user, $isDiscreet, $at, $session): SosEvent {
            $event = SafetyEventLog::record(
                type: $isDiscreet ? SafetyEventType::DiscreetAlert : SafetyEventType::Sos,
                user: $user,
                /*
                 * Always `critical`, for both kinds. A silent alert is not a smaller emergency —
                 * it is the same emergency raised by somebody who cannot afford to be seen
                 * raising it, which if anything is the more dangerous of the two.
                 */
                severity: SafetySeverity::Critical,
                tripSession: $session,
                booking: $this->bookingOn($user, $session),
                metadata: [
                    'discreet' => $isDiscreet,
                    // Whether we knew where they were. The coordinates go on the SOS row itself.
                    'hasLocation' => $at !== null,
                ],
            );

            $sos = new SosEvent;

            $sos->fill([
                'safety_event_id' => $event->id,
                /*
                 * The window the client gives them to cancel, copied onto the row. A dispute or a
                 * review later asks "how long did she have to take it back", and the answer has to
                 * be the number that was in force then rather than whatever it became.
                 */
                'countdown_seconds' => SafetySettings::sosCountdownSeconds(),
                'is_discreet' => $isDiscreet,
                'location_at_trigger' => $at,
            ]);

            $sos->save();

            return $sos;
        });
    }

    /**
     * "It was an accident."
     *
     * 🔒 Cancelling does NOT delete anything. The row stays, with a cancellation time on it, and
     * `safety_events` cannot be deleted at all. That is not bureaucracy: a pattern of SOS presses
     * cancelled seconds later, on the same route, with the same driver, is exactly the signal a
     * safety team needs — and it is invisible if each one erases itself.
     *
     * Only the person who raised it may cancel it, and only while it is still theirs to cancel:
     * once an operator has picked it up, it is a case being handled and the resolution is theirs
     * to record.
     */
    public function cancel(User $user, SosEvent $sos): SosEvent
    {
        if ($sos->safetyEvent->user_id !== $user->id) {
            // 404-shaped: an SOS id must not be probeable for existence.
            throw DomainException::of(ErrorCode::NotFound);
        }

        if ($sos->cancelled_at !== null) {
            throw DomainException::of(ErrorCode::SosAlreadyResolved, fields: [
                'cancelledAt' => [$sos->cancelled_at->toIso8601String()],
            ]);
        }

        if ($sos->first_touch_at !== null) {
            /*
             * Somebody is already on it. Refusing rather than silently accepting, because the
             * person needs to know a human is now involved — "I cancelled it" and "an operator is
             * calling me" are different situations to be in.
             */
            throw DomainException::of(ErrorCode::SosAlreadyResolved, fields: [
                'respondedAt' => [$sos->first_touch_at->toIso8601String()],
            ]);
        }

        $sos->forceFill(['cancelled_at' => now()])->save();

        return $sos;
    }

    /**
     * The run this person is on right now, if any — as a driver or as a passenger.
     *
     * Best effort, and `null` is fine: an SOS raised at a bus stop before the car arrives is still
     * an SOS. Linking it to a trip when we can is what lets the dashboard show the vehicle, the
     * other people in the car, and the GPS trail.
     */
    private function currentTripOf(User $user): ?TripSession
    {
        return TripSession::query()
            ->whereIn('current_status', ['en_route', 'at_pickup', 'in_progress'])
            ->where(function ($query) use ($user): void {
                $query
                    ->whereIn('scheduled_trip_id', Booking::query()
                        ->where('passenger_user_id', $user->id)
                        ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Pending])
                        ->select('scheduled_trip_id'))
                    ->orWhereIn('scheduled_trip_id', ScheduledTrip::query()
                        ->whereIn('commute_offer_id', CommuteOffer::query()
                            ->where('driver_profile_id', $user->id)
                            ->select('id'))
                        ->select('id'));
            })
            ->latest('started_at')
            ->first();
    }

    private function bookingOn(User $user, ?TripSession $session): ?Booking
    {
        if ($session === null) {
            return null;
        }

        return Booking::query()
            ->where('scheduled_trip_id', $session->scheduled_trip_id)
            ->where('passenger_user_id', $user->id)
            ->first();
    }
}
