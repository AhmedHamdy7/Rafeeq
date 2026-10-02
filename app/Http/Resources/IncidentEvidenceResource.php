<?php

namespace App\Http\Resources;

use App\Domains\Safety\Models\IncidentEvidence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One file attached to a report, back to the person who attached it.
 *
 * 🔒 **There is no path and no URL here, and there is not going to be one.** Pitfall #23: a stored
 * path must never appear in a payload. The reporter knows what they sent — they chose the file
 * seconds ago — and the reviewer reads it through the dashboard, which authenticates against a
 * different user table entirely. So there is nothing in this shape to forward, nothing to screenshot
 * and nothing to adjust into a guess at somebody else's file.
 *
 * What is here is what a screen needs to show "3 files attached" and let somebody notice they
 * attached four by mistake.
 *
 * `file_hash` is also absent. It is the chain of custody, not information for the uploader, and a
 * hash in a response is a way to test whether a particular file is already held.
 *
 * @mixin IncidentEvidence
 */
final class IncidentEvidenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind?->value,

            /*
             * When it may be destroyed. Shown because somebody who sends a photograph of a bad
             * moment is owed the answer to "how long do you keep this", and a date on the screen is
             * a better answer than a policy page.
             */
            'purgeAfter' => $this->purge_after?->toDateString(),

            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
