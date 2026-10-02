<?php

namespace App\Http\Resources;

use App\Domains\Rating\Models\Rating;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A rating, back to the person who wrote it.
 *
 * 🔴 **This shape is only ever used for the reviewer's OWN rating.** That is why it carries the
 * stars and the comment unconditionally: you are allowed to read what you yourself wrote, before
 * anybody else can.
 *
 * Reading somebody else's rating is a different shape with a different filter — see
 * `Rating::scopeVisible()` and pitfall #26. Do not reach for this class there, however convenient:
 * the moment it is used for a rating the viewer did not write, the double-blind protection is a
 * comment rather than a rule.
 *
 * 🔒 `reviewedUserId` is deliberately absent even here. The reviewer knows perfectly well who they
 * rated — they had just travelled with them — and echoing a user id back turns a rating into a way
 * to resolve somebody's identifier.
 *
 * @mixin Rating
 */
final class RatingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bookingId' => $this->booking_id,
            'direction' => $this->direction->value,
            'stars' => $this->stars,
            'comment' => $this->comment,
            'tags' => $this->whenLoaded('tags', fn () => $this->tagValues(), []),

            /*
             * 🔴 Whether the other person can see it yet — stated, rather than left to be worked
             * out from a timestamp. `false` says nothing about whether THEY have rated: it is also
             * false when nobody has, and the client must not word it as "waiting for them".
             */
            'isVisible' => $this->isVisible(),

            /*
             * Until when this may still be changed, or null once it cannot. Null covers both
             * reasons — the clock ran out, or it became visible — because the client's behaviour is
             * the same either way and distinguishing them would hint at the other person's.
             */
            'editableUntil' => $this->isVisible() ? null : $this->edit_deadline_at?->toIso8601String(),

            'editedAt' => $this->edited_at?->toIso8601String(),
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }

    /**
     * The tags on this rating, as their enum values.
     *
     * A foreach with an annotation rather than `pluck()`: a mapped collection loses its element
     * type, and the published contract then carries an untyped hole where the mobile team needs a
     * list of strings (standard #37).
     *
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
