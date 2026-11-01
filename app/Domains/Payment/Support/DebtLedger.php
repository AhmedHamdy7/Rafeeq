<?php

namespace App\Domains\Payment\Support;

use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Actions\SuspendForDebtAction;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtAccount;
use App\Domains\Payment\Models\DebtEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The only way anybody's debt to RAFEEQ changes (PAY-08, decision D-0005; Feature 03 backend
 * file L-2). The shared contract every workstream writes through — nobody builds a second ledger.
 *
 * ```php
 * DebtLedger::charge($user, DebtEntryType::Fee, 2_750, 'passenger_no_show', $booking);
 * DebtLedger::credit($user, DebtEntryType::Credit, 2_750, 'appeal_upheld', $booking);
 * DebtLedger::owedBy($user);                       // both sides, what she owes now
 * DebtLedger::owedBy($user, DebtSide::Passenger);  // one side
 * ```
 *
 * 🔴 The ledger is the truth and `debt_accounts` is a projection of it. Both are written here and
 * nowhere else, in one transaction, under a lock on the account row — so `balance_after_piastres`
 * on each line is the running total at that moment, and two settlements finishing in the same
 * second cannot both read the old figure. `payments:reconcile-balances` compares them every night.
 *
 * 🔴 Two SIDES per person, never mixed (see {@see DebtSide}). The side is, in order: the one the
 * caller names; else the person's role on the booking (its driver → driver side, anybody else →
 * passenger side); else `driver` for a commission or a late fee; else `passenger`.
 *
 * Amounts are always POSITIVE at this API. `charge()` adds to what she owes, `credit()` takes it
 * down; the line stores the sign. A balance may go below zero: that is a credit she will be
 * discounted next time (PAY-10), never money she can withdraw (Q-006).
 *
 * "Once" is the database's job: pass an `$idempotencyKey` (one commission per booking, one late
 * fee per week…) and a repeat returns the line already written instead of writing a second.
 */
final class DebtLedger
{
    /**
     * Something she now owes: a commission, a fee, a late fee, a correction upwards.
     *
     * @param  array<string, mixed>  $meta  free detail kept on the line (the fee's step, the rule ID…)
     */
    public static function charge(
        User $user,
        DebtEntryType $type,
        int $amountPiastres,
        string $reasonCode,
        ?Booking $booking = null,
        array $meta = [],
        ?DebtSide $side = null,
        ?string $idempotencyKey = null,
    ): DebtEntry {
        if (! $type->mayIncrease()) {
            throw new InvalidArgumentException("A {$type->value} line cannot add to a debt.");
        }

        return self::record(
            $user->getKey(),
            self::sideFor($user, $booking, $type, $side),
            $type,
            self::positive($amountPiastres),
            $reasonCode,
            bookingId: $booking?->getKey(),
            meta: $meta,
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * Something that takes her debt down: a credit (appeal upheld after payment), a coupon, a
     * confirmed payment, a write-off, a correction downwards.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function credit(
        User $user,
        DebtEntryType $type,
        int $amountPiastres,
        string $reasonCode,
        ?Booking $booking = null,
        array $meta = [],
        ?DebtSide $side = null,
        ?string $idempotencyKey = null,
    ): DebtEntry {
        if (! $type->mayDecrease()) {
            throw new InvalidArgumentException("A {$type->value} line cannot reduce a debt.");
        }

        return self::record(
            $user->getKey(),
            self::sideFor($user, $booking, $type, $side),
            $type,
            -self::positive($amountPiastres),
            $reasonCode,
            bookingId: $booking?->getKey(),
            meta: $meta,
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * What she owes right now, never below zero: one side, or both added together.
     *
     * A credit on one side does NOT pay the other side's debt — they follow different rules
     * (weekly commission for a driver, the next-ride fee line for a passenger) and are settled
     * by different people.
     */
    public static function owedBy(User|string $user, ?DebtSide $side = null): int
    {
        $sides = $side === null ? DebtSide::cases() : [$side];

        $owed = 0;

        foreach ($sides as $one) {
            $owed += max(0, self::balanceOf($user, $one));
        }

        return $owed;
    }

    /**
     * The signed balance on one side: positive owed, negative credit.
     */
    public static function balanceOf(User|string $user, DebtSide $side): int
    {
        return (int) DebtAccount::query()
            ->where('user_id', $user instanceof User ? $user->getKey() : $user)
            ->where('side', $side->value)
            ->value('balance_piastres');
    }

    /**
     * Writes one line and moves the projection with it. The low-level door: `charge()` and
     * `credit()` are the contract, this is for the paths that need a payment, an admin, a line
     * being answered or a week other than the current one.
     *
     * @param  int  $signedAmount  positive adds to the debt, negative reduces it
     * @param  array<string, mixed>  $meta
     * @param  string|null  $weekOf  the settlement week the line belongs to; default: this one
     */
    public static function record(
        string $userId,
        DebtSide $side,
        DebtEntryType $type,
        int $signedAmount,
        string $reasonCode,
        ?string $bookingId = null,
        ?string $debtPaymentId = null,
        ?string $reversesEntryId = null,
        ?string $adminId = null,
        array $meta = [],
        ?string $idempotencyKey = null,
        ?string $weekOf = null,
    ): DebtEntry {
        if ($signedAmount === 0) {
            throw new InvalidArgumentException('A ledger line of zero records nothing.');
        }

        if (($signedAmount > 0 && ! $type->mayIncrease()) || ($signedAmount < 0 && ! $type->mayDecrease())) {
            throw new InvalidArgumentException("A {$type->value} line cannot move a debt that way.");
        }

        return DB::transaction(function () use (
            $userId, $side, $type, $signedAmount, $reasonCode, $bookingId, $debtPaymentId,
            $reversesEntryId, $adminId, $meta, $idempotencyKey, $weekOf,
        ): DebtEntry {
            $account = self::lockedAccount($userId, $side);

            // Under the account lock, so two writers with the same key are serialised and the
            // second one finds the first one's line.
            if ($idempotencyKey !== null) {
                $existing = DebtEntry::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            $after = $account->balance_piastres + $signedAmount;

            $entry = new DebtEntry;
            $entry->user_id = $userId;
            $entry->side = $side;
            $entry->type = $type;
            $entry->amount_piastres = $signedAmount;
            $entry->balance_after_piastres = $after;
            $entry->reason_code = $reasonCode;
            $entry->week_of = $weekOf ?? SettlementWeek::current();
            $entry->booking_id = $bookingId;
            $entry->debt_payment_id = $debtPaymentId;
            $entry->reverses_entry_id = $reversesEntryId;
            $entry->created_by_admin_id = $adminId;
            $entry->idempotency_key = $idempotencyKey;
            $entry->meta = $meta === [] ? null : $meta;
            $entry->save();

            $account->forceFill(['balance_piastres' => $after])->save();

            /*
             * 🔴 A passenger's debt rides on her next booking as a separate line (PAY-16), so the
             * line follows every change to it — a fee recorded, a credit, a payment confirmed.
             */
            if ($side === DebtSide::Passenger) {
                FeeLines::refresh($userId);

                // Over the limit is immediate (PAY-17, PAY-46); after the money is safely written.
                if ($signedAmount > 0) {
                    DB::afterCommit(fn () => app(SuspendForDebtAction::class)->passengerIfDue($userId));
                }
            }

            // Money off a debt may be what lifts a suspension for it (PAY-45, PAY-46).
            if ($signedAmount < 0) {
                DB::afterCommit(fn () => app(SuspendForDebtAction::class)->liftIfPaid($userId, $side));
            }

            return $entry;
        });
    }

    /**
     * The account row, created on first use and locked for the rest of the transaction.
     */
    public static function lockedAccount(string $userId, DebtSide $side): DebtAccount
    {
        // `insertOrIgnore` rather than read-then-create: two first lines for the same person in the
        // same second would otherwise both try to create the row, and the second would fail.
        DebtAccount::query()->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'user_id' => $userId,
            'side' => $side->value,
            'balance_piastres' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DebtAccount::query()
            ->where('user_id', $userId)
            ->where('side', $side->value)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private static function sideFor(User $user, ?Booking $booking, DebtEntryType $type, ?DebtSide $side): DebtSide
    {
        if ($side !== null) {
            return $side;
        }

        if ($booking !== null) {
            return $booking->driver_profile_id === $user->getKey() ? DebtSide::Driver : DebtSide::Passenger;
        }

        return $type === DebtEntryType::Commission || $type === DebtEntryType::LateFee
            ? DebtSide::Driver
            : DebtSide::Passenger;
    }

    private static function positive(int $amountPiastres): int
    {
        if ($amountPiastres <= 0) {
            throw new InvalidArgumentException('Pass a positive amount; charge() and credit() set the sign.');
        }

        return $amountPiastres;
    }
}
