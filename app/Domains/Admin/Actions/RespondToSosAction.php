<?php

namespace App\Domains\Admin\Actions;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Safety\Enums\SafetyEventType;
use App\Domains\Safety\Enums\SafetySeverity;
use App\Domains\Safety\Enums\SosResolution;
use App\Domains\Safety\Models\SosEvent;
use App\Domains\Safety\Support\SafetyEventLog;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * The safety desk answering an SOS (Chapter 10 + Chapter 12, the SAFETY CASES section).
 *
 * Two steps, and the split between them is the point:
 *
 * 1. **Pick it up.** Writes `first_touch_at` — the Bible calls it "the most important
 *    performance metric", the number operations is measured on — and the responder. It is
 *    also what the member's app reads as `respondedAt`, which is the line that tells
 *    somebody in trouble that a human is now looking. So it is its own action, taken the
 *    moment an operator starts, not filled in afterwards when the case is closed.
 * 2. **Record how it ended** — false alarm, resolved, or handed to the police — with a
 *    note. Refused until step 1 happened, because resolving an alert nobody picked up
 *    would write a response time that measures the paperwork rather than the response.
 *
 * 🔴 Picking up is first-come under a row lock. Two operators reaching for the same alert
 * in the same second must not both be told it is theirs: one of them would then stand
 * down believing the other is on the phone, and the other would believe the same.
 *
 * 🔒 Every step is written twice, on purpose: to `admin_actions` (what staff did, for the
 * audit trail) and to `safety_events` as `admin_intervention` (the timeline of the
 * emergency itself, in the table that is never deleted). They answer different questions
 * — "what did this operator do today" and "what happened to her that night" — and a
 * reader of either should not need the other to get the whole of their answer.
 *
 * In the Admin domain rather than Safety because only the dashboard can reach it (D4); see
 * `OpenApiDocumentTest` for why that location is what keeps its error codes out of /v1.
 */
final readonly class RespondToSosAction
{
    public function acknowledge(SosEvent $sos, AdminUser $admin): SosEvent
    {
        return DB::transaction(function () use ($sos, $admin): SosEvent {
            $sos = $this->locked($sos);

            $this->assertStillLive($sos);

            if ($sos->first_touch_at !== null) {
                // Already mine: a double click is not an error worth showing anybody.
                if ($sos->responder_admin_id === $admin->id) {
                    return $sos;
                }

                throw DomainException::of(ErrorCode::SosAlreadyAcknowledged);
            }

            $sos->forceFill([
                'first_touch_at' => now(),
                'responder_admin_id' => $admin->id,
            ])->save();

            AdminActionLog::record(
                $admin,
                'sos.acknowledge',
                $sos,
                before: ['first_touch_at' => null],
                after: ['first_touch_at' => $sos->first_touch_at->toIso8601String()],
            );

            $this->timeline($sos, 'acknowledged');

            return $sos;
        });
    }

    public function resolve(SosEvent $sos, AdminUser $admin, SosResolution $resolution, string $note): SosEvent
    {
        return DB::transaction(function () use ($sos, $admin, $resolution, $note): SosEvent {
            $sos = $this->locked($sos);

            $this->assertStillLive($sos);

            if ($sos->first_touch_at === null) {
                throw DomainException::of(ErrorCode::SosNotAcknowledged);
            }

            /*
             * Not restricted to the operator who picked it up. A shift ends, and an alert
             * that only one named person may close is an alert that stays open overnight
             * because they went home. The audit row says who closed it, which is what
             * matters afterwards.
             */
            $sos->forceFill(['resolution' => $resolution->value])->save();

            AdminActionLog::record(
                $admin,
                'sos.resolve',
                $sos,
                before: ['resolution' => null],
                after: ['resolution' => $resolution->value],
                reason: $note,
            );

            $this->timeline($sos, 'resolved', ['resolution' => $resolution->value]);

            return $sos;
        });
    }

    /**
     * Re-read under a lock: the page that offered the button was rendered seconds ago, and
     * in those seconds the member may have cancelled or a colleague may have picked it up.
     */
    private function locked(SosEvent $sos): SosEvent
    {
        return SosEvent::query()
            ->whereKey($sos->getKey())
            ->lockForUpdate()
            ->with('safetyEvent.user')
            ->firstOrFail();
    }

    /**
     * An alert the member took back, or one already closed, is not something to act on.
     *
     * The same code the member's own app gets for the same situation, because it is the
     * same fact: this emergency has already been dealt with.
     */
    private function assertStillLive(SosEvent $sos): void
    {
        if ($sos->cancelled_at !== null || $sos->resolution !== null) {
            throw DomainException::of(ErrorCode::SosAlreadyResolved);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function timeline(SosEvent $sos, string $step, array $extra = []): void
    {
        $event = $sos->safetyEvent;

        SafetyEventLog::record(
            type: SafetyEventType::AdminIntervention,
            user: $event->user,
            // The emergency's own severity: an operator answering it does not make it less of one.
            severity: SafetySeverity::Critical,
            tripSession: $event->tripSession,
            booking: $event->booking,
            metadata: ['sosId' => $sos->id, 'step' => $step] + $extra,
        );
    }
}
