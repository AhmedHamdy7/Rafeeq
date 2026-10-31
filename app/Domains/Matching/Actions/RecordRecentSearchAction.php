<?php

namespace App\Domains\Matching\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Matching\Models\SavedSearch;
use App\Domains\Matching\Models\SearchHistory;
use App\Domains\Matching\Support\SearchCriteria;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Remembers a search so the box can prefill itself (Feature 03 v5's `search_history`).
 *
 * 🔴 **Called from the HTTP surface, never from inside the search engine.**
 * {@see SearchCommutesAction} is also run by
 * {@see NotifyMatchingDemandsAction} and
 * {@see MatchDemandToExistingCommutesAction} — background work that searches
 * on a passenger's behalf against a demand she saved weeks ago. Recording there would fill her
 * Recent Searches with searches she never made, every time a driver published anything near her.
 *
 * 🔴 **And it creates nothing but history.** v5: "Search alone doesn't create a demand" and "do not
 * auto-save demand". {@see CommuteDemand} and
 * {@see SavedSearch} are her deliberate acts — one makes the platform
 * notify her, the other she gave a name to. This is a convenience she never asked for, which is
 * exactly why it has to be erasable in one tap and must not quietly become either of the others.
 */
final readonly class RecordRecentSearchAction
{
    public function execute(User $user, SearchCriteria $criteria): void
    {
        /*
         * 🔴 A failure here must never fail the search.
         *
         * The person asked for commutes; remembering that she asked is a convenience on top. A
         * duplicate-key race between two taps, a column that cannot hold something — none of that
         * is a reason to show her an error instead of her results. Logged rather than swallowed, so
         * it is still findable if it ever happens.
         */
        try {
            $this->record($user, $criteria);
        } catch (Throwable $e) {
            Log::warning('A recent search could not be recorded.', [
                'user_id' => $user->id,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    private function record(User $user, SearchCriteria $criteria): void
    {
        /*
         * The same notion of "the same search" the result cache uses, rather than a second one
         * invented here. Two definitions of sameness in one feature drift, and the symptom would be
         * a list showing one route twice because the two hashes disagreed about one of her filters.
         */
        $signature = $criteria->signature($user->id);

        $existing = SearchHistory::query()
            ->where('user_id', $user->id)
            ->where('signature', $signature)
            ->first();

        if ($existing !== null) {
            /*
             * Only `searched_at` moves. `created_at` is what retention measures, so bumping it
             * would mean a route she runs every week is never pruned — and this is the one table
             * where never pruning is a privacy decision rather than a storage one.
             */
            $existing->forceFill(['searched_at' => now()])->save();

            return;
        }

        $row = new SearchHistory;

        /*
         * `forceFill`, because the model has no `$fillable` on purpose: a mass-assignable `user_id`
         * would let a request write a pair of coordinates into somebody else's history.
         */
        $row->forceFill([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            /*
             * The criteria in the shape the request accepts, so a client re-runs the search by
             * sending this object straight back. Not the request's own input: a search arrives as a
             * query string, where every value is a string, and `daysMask` coming back as `"62"`
             * would surprise a client doing arithmetic on its own data.
             */
            'criteria' => $criteria->toArray(),
            'signature' => $signature,
            'searched_at' => now(),
        ])->save();

        $this->trim($user);
    }

    /**
     * Keeps only the newest few.
     *
     * 🔒 A bound rather than a growing log, and it is a privacy choice more than a storage one.
     * Retention would eventually clear these anyway, but "we keep your last twenty searches" is a
     * promise that can be stated plainly, where "we keep ninety days of everywhere you looked" is
     * the same data described honestly and nobody would agree to it.
     */
    private function trim(User $user): void
    {
        $kept = (int) config('rafeeq.matching.recent_searches_kept');

        $expired = SearchHistory::query()
            ->forUser($user->id)
            ->skip($kept)
            ->take(100)
            ->pluck('id');

        if ($expired->isNotEmpty()) {
            SearchHistory::query()->whereIn('id', $expired->all())->delete();
        }
    }
}
