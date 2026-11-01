<?php

namespace App\Domains\Safety\Support;

use App\Domains\Payment\Support\FeeSettings;
use App\Domains\Safety\Models\Incident;
use Carbon\CarbonInterface;

/**
 * Whether a report's decision may be appealed, and why not (HISTORY-10; D-0021, SAFE-11).
 *
 * The rule, in the order it is checked:
 *
 * 1. Only a **decided** report — `resolved` or `closed`. One still being looked at has nothing to
 *    appeal yet.
 * 2. **Two appeals at most**, then final.
 * 3. Within **48 hours of the decision** — the same window as every other appeal on the platform
 *    (D-0021), read from the same setting so the two can never disagree.
 * 4. The **second** only with **new evidence**: more files attached than when the first was made.
 *
 * Written once here because two places ask it: the appeal itself, and the report's own payload,
 * which tells the app whether to show the button. A rule the screen and the server each wrote down
 * would one day offer an Appeal the server refuses.
 */
final class IncidentAppeals
{
    public const int MAX = 2;

    /**
     * Why the next appeal would be refused, as a stable code, or null when it would be accepted.
     *
     * `NOT_DECIDED` · `NO_APPEALS_LEFT` · `APPEAL_WINDOW_CLOSED` · `NEW_EVIDENCE_REQUIRED`
     */
    public static function refusal(Incident $incident): ?string
    {
        if (! $incident->status->isFinal()) {
            return 'NOT_DECIDED';
        }

        $made = self::made($incident);

        if ($made >= self::MAX) {
            return 'NO_APPEALS_LEFT';
        }

        $closesAt = self::closesAt($incident);

        if ($closesAt === null || $closesAt->isPast()) {
            return 'APPEAL_WINDOW_CLOSED';
        }

        if ($made > 0 && self::evidenceCount($incident) <= self::evidenceAtLastAppeal($incident)) {
            return 'NEW_EVIDENCE_REQUIRED';
        }

        return null;
    }

    /**
     * Until when an appeal may still be made, or null when it cannot be at all — including when the
     * only thing missing is new evidence, which she can still add (see `needsNewEvidence`).
     */
    public static function appealableUntil(Incident $incident): ?CarbonInterface
    {
        $refusal = self::refusal($incident);

        return $refusal === null || $refusal === 'NEW_EVIDENCE_REQUIRED'
            ? self::closesAt($incident)
            : null;
    }

    /**
     * 🔒 Whether a file may still be attached to a DECIDED report: only while it can still be
     * appealed. Evidence is how a second appeal earns its hearing, and refusing the upload would
     * make that rule impossible to meet; once the appeals are spent, the case file is closed.
     */
    public static function acceptsEvidence(Incident $incident): bool
    {
        return self::appealableUntil($incident) !== null;
    }

    public static function made(Incident $incident): int
    {
        $incident->loadMissing('appeals');

        return $incident->appeals->count();
    }

    public static function evidenceCount(Incident $incident): int
    {
        // Checked on the attributes rather than read: under `shouldBeStrict` reading a count the
        // query never selected throws, and a list that asked for `withCount` must not pay again.
        if (! array_key_exists('evidence_count', $incident->getAttributes())) {
            $incident->loadCount('evidence');
        }

        return (int) $incident->evidence_count;
    }

    private static function evidenceAtLastAppeal(Incident $incident): int
    {
        $incident->loadMissing('appeals');

        return (int) $incident->appeals->max('evidence_count');
    }

    /**
     * Measured from the decision being appealed. A second decision (after the first appeal) starts
     * its own 48 hours, because `resolved_at` is written again when staff decide again.
     */
    private static function closesAt(Incident $incident): ?CarbonInterface
    {
        return $incident->resolved_at?->copy()->addHours(FeeSettings::appealHours());
    }
}
