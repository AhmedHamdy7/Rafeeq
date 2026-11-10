<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Booking\Actions\CancelBookingAction;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Commute\Actions\ChangeCommuteStatusAction;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Enums\SecurityEventType;
use App\Domains\Identity\Enums\SessionRevocationReason;
use App\Domains\Identity\Models\AuthSession;
use App\Domains\Identity\Models\Device;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserPlace;
use App\Domains\Identity\Support\AccountDeletionCheck;
use App\Domains\Identity\Support\SecurityLog;
use App\Domains\Matching\Enums\CommuteDemandStatus;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Matching\Models\SavedSearch;
use App\Domains\Safety\Models\EmergencyContact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Deletes a member's account (F1-4, PROF-31, D-0028). Both stores refuse an app without it.
 *
 * Refused while she owes money or has a trip inside the fee window ({@see AccountDeletionCheck}).
 * Otherwise, in one transaction:
 *
 *   1. **her trips are cancelled for free** — every seat she holds (the seat goes to the waiting
 *      list, the driver is told) and every commute she drives (every rider is told). Nothing is
 *      charged: the check above is what guaranteed there was nothing to charge;
 *   2. **every open question she left is closed** — pending seat requests, saved requests;
 *   3. **every session ends**, and every device forgets its push token;
 *   4. **she is anonymised, not erased** — name, photo, email, date of birth and the things she
 *      saved (places, searches, trusted contacts) go, the phone number is replaced so it can sign
 *      up again, and the row is soft-deleted. Bookings, payments, ratings and incidents stay,
 *      attached to an id that no longer names anybody (ERD §18: the financial and safety record
 *      outlives the person).
 *
 * 🔴 Immediate, not a 30-day grace. `PENDING_DELETION` stays reserved: how long anything is kept,
 * and whether there is a way back, is still open (Q-055, Q-011). Verification documents are kept
 * for the same reason until Q-055 says otherwise — they are the fraud record.
 */
final readonly class DeleteAccountAction
{
    public function __construct(
        private CancelBookingAction $bookings,
        private ChangeCommuteStatusAction $commutes,
    ) {}

    public function execute(User $user, bool $skipChecks = false): void
    {
        if (! $skipChecks) {
            AccountDeletionCheck::assertCanDelete($user);
        }

        DB::transaction(function () use ($user): void {
            foreach (AccountDeletionCheck::upcomingPassengerSeats($user) as $booking) {
                // By the system, so no fee and nothing on a record nobody will ever read again.
                $this->bookings->bySuspension($booking, 'account_deleted');
            }

            CommuteOffer::query()
                ->where('driver_profile_id', $user->id)
                ->whereNot('status', CommuteOfferStatus::Archived->value)
                ->get()
                // A reason means "not his choice to charge for" (C-8): nothing is charged.
                ->each(fn (CommuteOffer $offer) => $this->commutes->archive($offer, 'account_deleted'));

            SeatRequest::query()
                ->where('passenger_user_id', $user->id)
                ->whereIn('status', [SeatRequestStatus::Pending->value, SeatRequestStatus::Waitlisted->value, SeatRequestStatus::Counteroffered->value])
                ->update(['status' => SeatRequestStatus::Withdrawn->value]);

            CommuteDemand::query()
                ->where('passenger_user_id', $user->id)
                ->whereIn('status', [CommuteDemandStatus::Active->value, CommuteDemandStatus::Paused->value, CommuteDemandStatus::Matched->value])
                ->update(['status' => CommuteDemandStatus::Cancelled->value]);

            AuthSession::query()
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->get()
                ->each(fn (AuthSession $session) => $session->revoke(SessionRevocationReason::AccountDeleted));

            Device::query()->where('user_id', $user->id)->update(['push_token' => null, 'revoked_at' => now()]);

            UserPlace::query()->where('user_id', $user->id)->delete();
            SavedSearch::query()->where('user_id', $user->id)->delete();
            EmergencyContact::query()->where('user_id', $user->id)->delete();

            // Recorded BEFORE the phone goes, so the audit trail still says whose account it was.
            SecurityLog::record(SecurityEventType::AccountDeleted, $user, metadata: [
                'phone' => SecurityLog::maskPhone($user->phone_e164),
            ]);

            $user->forceFill([
                // 20 characters, the column's width. Not a phone, so it can never sign in.
                'phone_e164' => 'deleted_'.Str::lower(Str::random(12)),
                // Not null: `chk_users_basic_profile_complete` keeps a complete profile named, and a
                // fixed word names nobody.
                'full_name' => 'deleted',
                'public_first_name' => null,
                'profile_photo_path' => null,
                'email' => null,
                'email_verified_at' => null,
                'date_of_birth' => null,
                'account_status' => AccountStatus::Deleted->value,
            ])->save();

            $user->delete();
        });
    }
}
