<?php

namespace App\Http\Resources;

use App\Domains\Rating\Models\Rating;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One review as a STRANGER sees it — and as its subject sees it.
 *
 * 🔒 **There is no reviewer here, and the date is a month rather than a day.** The sources do not
 * specify either way, so this is a judgement, and it is the one decision in this class worth
 * reading:
 *
 * A commute carries one to three passengers. A review with an exact date therefore identifies the
 * journey, and the journey identifies the person — so a precise date names the reviewer even when
 * the payload does not. That matters here more than on an ordinary marketplace for one specific
 * reason: **the driver already has the passenger's pickup point.** She knows her front door. A
 * passenger who writes honestly about a driver who can find her house, and can be identified from
 * the date she wrote it, is exposed in a way an anonymous shopper never is — and the result is not
 * fairer reviews, it is quieter ones.
 *
 * So: stars, tags and words, with the month and nothing else. A reader loses very little (recency
 * at month resolution is what a profile actually conveys) and the person who wrote it keeps the only
 * protection that makes honesty safe.
 *
 * The same shape serves "reviews about me" deliberately. Showing somebody who rated them two stars
 * is the retaliation vector, not a courtesy.
 *
 * 🔒 Nothing identifies the booking either. A booking id would let the subject look the journey up
 * and read the reviewer's name off its passenger list.
 *
 * @mixin Rating
 */
final class PublicReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            /*
             * Which way round, so a reader knows whether this is a passenger writing about a
             * driver or the reverse. Not who — see the class note.
             */
            'direction' => $this->direction->value,

            'stars' => $this->stars,
            'comment' => $this->comment,
            'tags' => $this->whenLoaded('tags', fn () => $this->tagValues(), []),

            /*
             * The MONTH it was written, as `YYYY-MM`. Deliberately not a timestamp: see the class
             * note on why an exact date names the reviewer on a three-seat commute.
             */
            'month' => $this->visible_at?->format('Y-m'),

            // Whether it was changed before it became readable. Honest about the text's history
            // without revealing what it used to say.
            'wasEdited' => $this->edited_at !== null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function tagValues(): array
    {
        $values = [];

        foreach ($this->tags as $tag) {
            $values[] = $tag->tag;
        }

        return $values;
    }
}
