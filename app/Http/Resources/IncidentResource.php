<?php

namespace App\Http\Resources;

use App\Domains\Safety\Models\Incident;
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
            'evidenceCount' => $this->whenLoaded('evidence', fn () => $this->evidence->count(), 0),
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
