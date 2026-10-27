<?php

namespace App\Domains\Safety\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Safety\Models\BlockedUser;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;

/**
 * "I do not want to be matched with this person again" (screen 26).
 *
 * 🔴 The block is already enforced, and has been since Phase 6: `HardFilters` excludes a commute in
 * BOTH directions — mine on them and theirs on me — inside the search query itself, before anything
 * is scored. Pitfall #27 is checking one direction only, and the reason it matters is uncomfortable:
 * if only the blocker's direction were checked, somebody who blocks a person they are afraid of
 * would still appear in that person's search results. The protection would run the wrong way.
 *
 * So this Action writes the row that the existing filter reads. What it deliberately does NOT do:
 *
 * - **It does not cancel existing bookings.** Chapter 10: "existing completed history remains". A
 *   block is about the future; unwinding this week's arrangement is a cancellation, which is a
 *   separate decision with its own consequences for the other four people in the car.
 * - **It does not tell the other person.** There is no notification, no visible marker, nothing in
 *   any payload they can read. Somebody blocking a person they are frightened of must not thereby
 *   inform them of it.
 * - **It records no reason to anybody but us.** The optional reason is for a support case, never
 *   shown to the blocked party.
 *
 * ---
 *
 * 🔴 **This diverges from Feature 03 v5 §248, knowingly, and the divergence is recorded here
 * because that is what the house rule requires of a conflict rather than a silent choice.**
 *
 * v5 says: *"block only after shared trip and only after upcoming shared confirmed bookings
 * canceled."* Both halves are refused here, and both would cost the person the rule exists to
 * protect:
 *
 *   1. **"Only after a shared trip."** Under that rule a woman cannot block somebody she has never
 *      ridden with — which now includes anybody who has written to her on a seat-request thread,
 *      since chat starts at the request and no ride has happened yet. The place a stranger can
 *      first say something unwelcome would be the one place she could not act on it.
 *   2. **"Only after upcoming shared confirmed bookings are cancelled."** That makes the block
 *      conditional on her first giving up a seat — and, once the cancellation policy exists, on
 *      paying to do so. Somebody frightened of a driver she is booked with on Thursday would have
 *      to pay before she could stop being matched with him again.
 *
 * What makes refusing it safe rather than merely kinder is that **a block here does not pretend to
 * end an existing arrangement.** v5's second clause reads as a guard against block-as-cancellation
 * — blocking to make a booking disappear — and nothing of the sort is possible: the block changes
 * future matching only, in both directions, and leaves every confirmed seat exactly where it was.
 * So the worry the rule addresses does not arise, and the cost it imposes is paid for nothing.
 *
 * Her existing booking is still hers to cancel, with whatever that costs. She simply does not have
 * to do it first in order to be left alone afterwards.
 */
final readonly class BlockUserAction
{
    public function block(User $blocker, string $blockedUserId, ?string $reason = null): BlockedUser
    {
        if ($blocker->id === $blockedUserId) {
            throw DomainException::of(ErrorCode::CannotBlockSelf);
        }

        /*
         * The target must exist, but a miss is a 404 rather than a named refusal — a blocking
         * endpoint that distinguished "no such person" from "done" would be a way to test whether a
         * given id is on the platform.
         */
        User::query()->whereKey($blockedUserId)->firstOrFail();

        $existing = BlockedUser::query()
            ->where('blocker_user_id', $blocker->id)
            ->where('blocked_user_id', $blockedUserId)
            ->first();

        if ($existing !== null) {
            throw DomainException::of(ErrorCode::AlreadyBlocked);
        }

        $block = new BlockedUser;

        $block->fill([
            'blocker_user_id' => $blocker->id,
            'blocked_user_id' => $blockedUserId,
            'reason' => $reason,
        ]);

        $block->save();

        return $block;
    }

    /**
     * Unblocking, which only the blocker can do and which takes effect on the next search.
     *
     * 🔒 No safety event is written for a block or an unblock, and that is deliberate. `safety_events`
     * is read by operators; a row saying "she blocked him" in a table staff browse turns a private
     * protective act into something discussable. The block itself is the record, it is visible only
     * to the person who made it, and it is enough.
     */
    public function unblock(User $blocker, string $blockedUserId): void
    {
        BlockedUser::query()
            ->where('blocker_user_id', $blocker->id)
            ->where('blocked_user_id', $blockedUserId)
            ->delete();
    }
}
