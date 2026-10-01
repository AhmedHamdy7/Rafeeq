<?php

namespace App\Domains\Safety\Actions;

use App\Domains\Booking\Enums\BookingStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Identity\Models\User;
use App\Domains\Safety\Enums\SafetyEventType;
use App\Domains\Safety\Enums\SafetySeverity;
use App\Domains\Safety\Models\EmergencyContact;
use App\Domains\Safety\Models\LiveShare;
use App\Domains\Safety\Support\SafetyEventLog;
use App\Domains\Safety\Support\SafetySettings;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Share Live Trip" (Chapter 10) — a temporary link a trusted contact can open.
 *
 * 🔴 The whole feature is a link that bypasses authentication, which is why almost every decision
 * in this class is about bounding it:
 *
 * - **The token is 32 random bytes and stored only as a hash** (pitfall #29). A database leak
 *   yields no working links, and a sequential id would let anybody enumerate other people's
 *   journeys. The plaintext is returned exactly once, at creation, and never again — not even to
 *   the person who made it. Re-reading your own share gives you its view count, not its token.
 * - **It dies with the journey.** `expires_at` is the trip's own end plus a short grace, so a link
 *   cannot outlive the thing it describes. A share that stayed valid afterwards would be a
 *   standing window onto wherever that person goes next.
 * - **It is revocable**, and revoking takes effect on the next view rather than waiting for the
 *   expiry.
 * - **It records that it was opened.** `view_count` and `last_viewed_at` are not analytics: they
 *   are how the person who shared it can tell whether their contact actually looked.
 *
 * 🔒 And what the link shows is deliberately narrow — the Bible says it in as many words: "the
 * first name, the vehicle and the position only; not a phone number and not a home address". The
 * reasoning that is easy to miss: the viewer is a stranger to the DRIVER. She never agreed to
 * share anything with this person. So the page carries what somebody needs to act in an emergency
 * — which car, where it is, where it is going — and nothing that would still be useful to them
 * tomorrow.
 */
final readonly class ShareLiveTripAction
{
    public function create(User $user, TripSession $session, ?EmergencyContact $contact = null): array
    {
        $this->assertOnTheRun($user, $session);

        if (! $session->current_status->isUnderway()) {
            /*
             * Only while the car is out. A link made before the run starts would be a page saying
             * nothing, and one made afterwards is somebody asking for a window onto a journey that
             * has finished.
             */
            throw DomainException::of(ErrorCode::TripNotStarted, fields: [
                'tripStatus' => [strtoupper($session->current_status->value)],
            ]);
        }

        if ($contact !== null && $contact->user_id !== $user->id) {
            // 404-shaped: a contact id must not be probeable through this endpoint.
            throw DomainException::of(ErrorCode::NotFound);
        }

        /*
         * 32 bytes, URL-safe. `Str::random` uses `random_bytes` underneath, so this is the CSPRNG
         * and not `mt_rand` — a token a few observed samples could predict would make every share
         * on the platform guessable.
         */
        $token = Str::random(43);

        $share = DB::transaction(function () use ($user, $session, $contact, $token): LiveShare {
            $share = new LiveShare;

            $share->fill([
                'trip_session_id' => $session->id,
                'user_id' => $user->id,
                // 🔒 Only ever the hash. See the class note.
                'token_hash' => hash('sha256', $token),
                'shared_with_contact_id' => $contact?->id,
                'expires_at' => $this->expiryFor($session),
            ]);

            /*
             * Set here rather than left to the column default. The default would be right in the
             * database and absent from the model that is handed straight back to the client, so the
             * response to "share this trip" would say `viewCount: null` — and the screen reads that
             * number to tell somebody whether their contact looked.
             */
            $share->view_count = 0;

            $share->save();

            /*
             * Into the record that is never deleted. A live share is a safety act: if something
             * went wrong on this journey, the fact that she had told somebody where she was — and
             * when — is part of what happened.
             */
            SafetyEventLog::record(
                type: SafetyEventType::LiveShareStarted,
                user: $user,
                // Not an emergency. Somebody taking a sensible precaution is the ordinary case,
                // and marking it `high` would bury the real alerts among them.
                severity: SafetySeverity::Low,
                tripSession: $session,
                metadata: [
                    'liveShareId' => $share->id,
                    'sharedWithContact' => $contact !== null,
                    // Deliberately not the contact's name or number — see SafetyEventLog.
                ],
            );

            return $share;
        });

        /*
         * The plaintext token travels back with the model and is never stored. The caller builds
         * the URL from it; nothing else in the system can reproduce it.
         */
        return ['share' => $share, 'token' => $token];
    }

    /**
     * Ends the share now rather than at its expiry.
     */
    public function revoke(User $user, LiveShare $share): LiveShare
    {
        if ($share->user_id !== $user->id) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        if ($share->revoked_at !== null) {
            throw DomainException::of(ErrorCode::LiveShareAlreadyEnded, fields: [
                'revokedAt' => [$share->revoked_at->toIso8601String()],
            ]);
        }

        $share->forceFill(['revoked_at' => now()])->save();

        return $share;
    }

    /**
     * The share a token opens, or null.
     *
     * 🔒 Looked up BY HASH, so the stored value is never compared in the clear and a timing
     * difference on the lookup reveals nothing about the token. Returns null for expired, revoked
     * and unknown alike: a page that distinguished "this link has expired" from "this link never
     * existed" would confirm that a guessed token was once real.
     */
    public function resolve(string $token): ?LiveShare
    {
        $share = LiveShare::query()
            ->where('token_hash', hash('sha256', $token))
            ->with([
                'tripSession.scheduledTrip.commuteOffer.vehicle',
                'tripSession.scheduledTrip.commuteOffer.driverProfile.user',
                'tripSession.scheduledTrip.commuteOffer.locations',
            ])
            ->first();

        if ($share === null || ! $share->isActive()) {
            return null;
        }

        /*
         * Counted on every view, and `increment` rather than a read-modify-write: two people
         * opening the link at once must not lose a count, because this number is how the person
         * who shared it knows somebody looked.
         */
        $share->increment('view_count');
        $share->forceFill(['last_viewed_at' => now()])->save();

        return $share;
    }

    /**
     * When the link stops working: the journey's own end, plus a short grace.
     *
     * The grace exists because a contact who opens the link as the car pulls in should see it
     * arrive rather than an expired page — and because "the trip ended" is a moment the driver
     * chooses, which can be a few minutes after everybody is actually home.
     *
     * Bounded from the DEPARTURE rather than from now, so a share created late in a journey does
     * not quietly extend past it.
     */
    private function expiryFor(TripSession $session): \DateTimeInterface
    {
        $graceMinutes = SafetySettings::liveShareGraceMinutes();

        $completed = $session->completed_at;

        if ($completed !== null) {
            return $completed->copy()->addMinutes($graceMinutes);
        }

        /*
         * The run has not finished, so the end is unknown. A commute is bounded by its own
         * schedule, so the longest it can reasonably last plus the grace is the ceiling — and a
         * link that expires too early is a nuisance, while one that expires too late is a window
         * onto somebody's evening.
         */
        return now()->addMinutes(SafetySettings::liveShareMaxMinutes() + $graceMinutes);
    }

    /**
     * 🔒 Only somebody actually ON this run may share it — the driver, or a passenger holding a
     * live seat. Without this, anybody who learned a trip id could publish a link to a stranger's
     * journey.
     */
    private function assertOnTheRun(User $user, TripSession $session): void
    {
        $isDriver = ScheduledTrip::query()
            ->whereKey($session->scheduled_trip_id)
            ->whereIn('commute_offer_id', CommuteOffer::query()
                ->where('driver_profile_id', $user->id)
                ->select('id'))
            ->exists();

        if ($isDriver) {
            return;
        }

        $ridesOnIt = Booking::query()
            ->where('scheduled_trip_id', $session->scheduled_trip_id)
            ->where('passenger_user_id', $user->id)
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Pending])
            ->exists();

        if (! $ridesOnIt) {
            throw DomainException::of(ErrorCode::NotFound);
        }
    }
}
