<?php

namespace App\Http\Resources;

use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Rating\Models\Rating;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A member's profile as somebody who has never met her sees it (Feature 02 v3:
 * `GET /users/{id}/public-profile`).
 *
 * 🔒 Built on top of {@see PersonSummary} rather than beside it. That class is already the one
 * answer to "what may one member know about another", and a second hand-written copy of those
 * rules is a second chance to get one of them subtly wrong — which, for a rule about who can find
 * out where a woman works, is not a cosmetic risk.
 *
 * Three deliberate decisions, because v3 words this screen loosely and each gap is a privacy call:
 *
 * 1. 🔴 **`publicFirstName`, never the full name.** v3 says a public viewer sees "user name", and
 *    the full name is what this repository has decided nobody else ever receives (Bible §15.2,
 *    pitfall #21) — the mobile guide states it as a flat promise and a test pins it. A public
 *    profile is the LAST place to start making exceptions: it is the one payload reachable for an
 *    arbitrary id. So "user name" is read as the public handle.
 *
 * 2. 🔴 **No area, though the design's subtitle shows one** ("Joined Mar 2025 · New Cairo"). We
 *    have no `area` field; the only way to produce one is to derive it from her home place, and a
 *    district derived from somebody's home address, handed to a stranger who typed a user id, is a
 *    rough answer to "where does she live". The join month is kept because it conveys the same
 *    "established member" signal and reveals nothing about anybody. If the area is wanted, it
 *    should be a field she sets and can leave blank, not one inferred from where she sleeps.
 *
 * 3. 🔴 **The driver badge is `approved`, never `applied`.** v3 is explicit — "verified driver
 *    badge only when actually approved" — and this is the field a passenger uses to decide a
 *    stranger is safe to ride with.
 *
 * Never present: full name, phone, email, date of birth, gender, any document or its path, staff
 * notes, or the id of any booking.
 *
 * @mixin User
 */
final class PublicProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $person = $this->resource;
        $person->loadMissing(['stats', 'verifications', 'driverProfile']);

        $isApprovedDriver = $person->driverProfile?->status === DriverProfileStatus::Approved;

        /*
         * Both roles' numbers, because one account is one person doing two things and the design
         * shows whichever the viewer came for. `PersonSummary` answers for one role at a time, so
         * it is asked twice rather than reimplemented.
         */
        $asPassenger = PersonSummary::for($person, asDriver: false);
        $asDriver = PersonSummary::for($person, asDriver: true);

        return [
            // Cast, so the published contract says `string` rather than leaving the mobile team a
            // `mixed` they cannot generate a client from: `getKey()` is untyped to Scramble.
            'id' => (string) $person->getKey(),
            'publicFirstName' => $person->public_first_name,

            /*
             * The derived avatar, or null for initials. Null is the normal case: the photo only
             * exists once an identity has been APPROVED, and a passenger-only account never gets
             * one (F02 §2). The client draws initials from `publicFirstName` when this is null.
             *
             * 🔒 This is a separate, resized image derived at approval — never the identity
             * document itself, which the same section forbids showing to anybody.
             */
            'avatarPath' => $person->profile_photo_path,

            // Month precision, like the public reviews, and for the same reason: a join DATE is a
            // fact about one person that nothing on this screen needs.
            'joinedMonth' => $person->created_at?->format('Y-m'),

            'trustLevel' => $asPassenger['trustLevel'],
            'verifiedLevels' => $asPassenger['verifiedLevels'],

            // 🔒 Approved, not applied. See the class note.
            'isVerifiedDriver' => $isApprovedDriver,

            'asPassenger' => [
                'completedTrips' => $asPassenger['completedTrips'],
                'rating' => $asPassenger['rating'],
                'onTimeRate' => $asPassenger['onTimeRate'],
            ],

            /*
             * Absent entirely for somebody who is not an approved driver, rather than present with
             * zeroes. Zeroes would render as a driver with a terrible record instead of a person
             * who has never driven here.
             */
            'asDriver' => $this->when($isApprovedDriver, fn (): array => [
                'completedTrips' => $asDriver['completedTrips'],
                'rating' => $asDriver['rating'],
                'onTimeRate' => $asDriver['onTimeRate'],
            ]),

            'reviews' => [
                'count' => $this->visibleReviews()->count(),
                // The most recent few, in the same shape the commute's review list uses — no
                // reviewer, month precision only. See `PublicReviewResource` for why.
                'items' => PublicReviewResource::collection(
                    $this->visibleReviews()->latest('visible_at')->limit(5)->get()
                ),
            ],
        ];
    }

    /**
     * Reviews about this person that are allowed to be seen.
     *
     * 🔴 `visible()` is applied explicitly. `Rating` deliberately has no global scope for it — a
     * default would silently make every future query safe-looking — so every reader has to say it,
     * and this is a reader.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Rating>
     */
    private function visibleReviews()
    {
        return Rating::query()
            ->where('reviewed_user_id', $this->resource->getKey())
            ->visible();
    }
}
