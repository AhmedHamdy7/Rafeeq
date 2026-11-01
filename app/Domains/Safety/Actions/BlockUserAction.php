<?php

namespace App\Domains\Safety\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Safety\Models\BlockedUser;
use App\Domains\Safety\Support\BlockedBookings;
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
 * So this Action writes the row that the existing filter reads, and then:
 *
 * - **It cancels every upcoming booking between the two** (Feature 03 files X-4, decision D5). A
 *   woman who blocks a driver must not find him at her door tomorrow. Cancelled as the BLOCKER,
 *   through the ordinary cancellation paths, so the normal rules apply — see {@see BlockedBookings},
 *   which also answers the preview she is shown first. Completed history is untouched.
 * - **It does not tell the other person.** They hear that a booking was cancelled, exactly as for
 *   any other cancellation, and nothing else: no notification of the block, no visible marker,
 *   nothing in any payload they can read. Somebody blocking a person they are frightened of must
 *   not thereby inform them of it.
 * - **It records no reason to anybody but us.** The optional reason is for a support case, never
 *   shown to the blocked party.
 *
 * ---
 *
 * 🔴 **Where this still differs from SAFE-15 / Feature 03 v5 §248, and why.**
 *
 * v5 says: *"block only after shared trip and only after upcoming shared confirmed bookings
 * canceled."* The partners' later decision (D5, 2026-10-31) replaced the second half: the block
 * itself cancels them, after a preview, instead of refusing until she has cancelled them herself.
 * That is what is built.
 *
 * The first half — "only after a shared trip" — is still not enforced. Under it a woman could not
 * block somebody she has never ridden with, which includes anybody who has written to her on a
 * seat-request thread: the place a stranger can first say something unwelcome would be the one
 * place she could not act on it. The app offers Block from the trip summary and history (the
 * other person's id is returned there once the trip has ended), and the server accepts it anywhere.
 */
final readonly class BlockUserAction
{
    public function __construct(private BlockedBookings $bookings) {}

    /**
     * What blocking this person would cancel and cost, before she decides (X-4's preview).
     *
     * The same refusals as the block itself, so the preview is no better a way to test whether an
     * id exists than the block is.
     *
     * @return array{bookingsToCancel: list<array<string, string|null>>, feePiastres: int, reason: string|null}
     */
    public function preview(User $blocker, string $blockedUserId): array
    {
        $this->assertBlockable($blocker, $blockedUserId);

        return BlockedBookings::preview($blocker, $blockedUserId);
    }

    public function block(User $blocker, string $blockedUserId, ?string $reason = null): BlockedUser
    {
        $this->assertBlockable($blocker, $blockedUserId);

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

        /*
         * After the row, so the pair is already apart in search by the time the seats are
         * released. Each cancellation commits on its own; one that can no longer be cancelled is
         * skipped and the block still stands.
         */
        $this->bookings->cancelAll($blocker, $blockedUserId);

        return $block;
    }

    private function assertBlockable(User $blocker, string $blockedUserId): void
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
