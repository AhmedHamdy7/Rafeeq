<?php

namespace App\Http\Resources;

use App\Domains\Safety\Models\Incident;
use App\Domains\Safety\Support\IncidentAppeals;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A report, back to the person who filed it.
 *
 * 🔒 What is absent, and why each one:
 *
 * - **Who it was about.** The reporter knows; echoing a user id back turns a report into a way to
 *   resolve somebody's identifier.
 * - **The assigned reviewer.** Staff handling a harassment case are not introduced to either party.
 * - **The evidence files.** A path never appears in a payload (pitfall #23). The reporter uploaded
 *   them and knows what they sent.
 *
 * @mixin Incident
 */
final class IncidentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category->value,

            /*
             * 🔴 Decided by the platform from the category, never by the reporter. Somebody who has
             * just been harassed cannot be asked to rate their own emergency, and a client that
             * could set this would own the ordering of the safety queue.
             */
            'severity' => $this->severity->value,

            'description' => $this->description,
            'status' => strtoupper($this->status->value),

            /*
             * When the platform has undertaken to respond by, written when the report was filed.
             * Fixed rather than recomputed on read: a deadline that can be recalculated is one that
             * can be quietly moved, and a missed deadline is the thing nobody should be able to move.
             */
            'slaDueAt' => $this->sla_due_at?->toIso8601String(),

            'resolution' => $this->resolution,
            'resolvedAt' => $this->resolved_at?->toIso8601String(),

            // Which journey it was about, when it named one.
            'bookingId' => $this->booking_id,

            /*
             * 🔴 Read from the count the query asked for, and that is a fix (HISTORY-11). The list
             * runs `withCount('evidence')` and this used to read only a LOADED relation — which the
             * list never loads — so every report in "My reports" said it had no files attached.
             */
            'evidenceCount' => IncidentAppeals::evidenceCount($this->resource),

            /*
             * Whether "Appeal" can be offered, answered by the same rule the appeal endpoint applies
             * (HISTORY-10, see IncidentAppeals) so the button and the server cannot disagree.
             *
             * - `count`: appeals made, 0–2.
             * - `appealableUntil`: show Appeal until then; null = not appealable (not decided yet,
             *   both appeals used, or the 48 hours have passed).
             * - `needsNewEvidence`: the next appeal is the second, and is accepted only after a new
             *   file is attached.
             */
            'appeal' => [
                'count' => IncidentAppeals::made($this->resource),
                'appealableUntil' => IncidentAppeals::appealableUntil($this->resource)?->toIso8601String(),
                'needsNewEvidence' => IncidentAppeals::made($this->resource) > 0
                    && IncidentAppeals::refusal($this->resource) === 'NEW_EVIDENCE_REQUIRED',
            ],

            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
