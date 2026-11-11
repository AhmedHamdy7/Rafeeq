<?php

namespace App\Domains\Payment\Support;

use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtAccount;
use App\Domains\Payment\Models\DebtEntry;
use Carbon\CarbonImmutable;

/**
 * Where one person stands on one side of the ledger, this moment (PAY-11 → PAY-17).
 *
 * 🔴 COMPUTED from the ledger, never tracked in a flag. Payments are applied to the OLDEST debt
 * first, so "what is still unpaid from week W" is simply: the balance, minus everything added in
 * the weeks after W. That one rule gives every state the cycle needs:
 *
 * | state     | means                                                          | rule   |
 * |-----------|----------------------------------------------------------------|--------|
 * | overdue   | something from a week whose Friday has passed is unpaid        | PAY-12 |
 * | severe    | something from a week that has missed N Fridays is unpaid      | PAY-45 |
 * | oldest    | the line where the unpaid money starts — its week, its date    | PAY-17 |
 *
 * A flag would go stale the moment staff confirmed a payment or a setting moved; this cannot.
 */
final readonly class DebtStanding
{
    public function __construct(
        public string $userId,
        public DebtSide $side,
        /** Signed: positive owed, negative a credit. */
        public int $balancePiastres,
        /** Owed from weeks whose Friday has passed (driver side). */
        public int $overduePiastres,
        /** Owed from weeks that have missed enough Fridays to suspend (driver side). */
        public int $severePiastres,
        /** The settlement week of the oldest money still unpaid, or null when nothing is owed. */
        public ?string $oldestUnpaidWeekOf,
        /** When the oldest money still unpaid was added. */
        public ?CarbonImmutable $oldestUnpaidAt,
        /** Late fees inside what is owed now (PAY-13). */
        public int $lateFeePiastres,
        public ?CarbonImmutable $suspendedAt,
    ) {}

    public static function of(User|string $user, DebtSide $side): self
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        $account = DebtAccount::query()->where('user_id', $userId)->where('side', $side->value)->first();
        $balance = (int) ($account?->balance_piastres ?? 0);

        $current = SettlementWeek::current();
        $severeFrom = SettlementWeek::shift($current, -(DebtSettings::weeksBeforeSuspension() - 1));

        $overdue = max(0, $balance - self::addedSince($userId, $side, $current));
        $severe = max(0, $balance - self::addedSince($userId, $side, $severeFrom));

        [$oldest, $lateFees] = self::oldestUnpaid($userId, $side, $balance);

        return new self(
            $userId,
            $side,
            $balance,
            $side === DebtSide::Driver ? $overdue : 0,
            $side === DebtSide::Driver ? $severe : 0,
            $oldest?->week_of?->toDateString(),
            $oldest?->created_at,
            $lateFees,
            $account?->suspended_at,
        );
    }

    public function owedPiastres(): int
    {
        return max(0, $this->balancePiastres);
    }

    /**
     * PAY-12: a driver with commission unpaid past its Friday may not publish or take new riders.
     */
    public function isBlocked(): bool
    {
        return $this->overduePiastres > 0;
    }

    /**
     * The deadline that matters to her now: the one she missed, or this week's Friday.
     */
    public function dueAt(): CarbonImmutable
    {
        return SettlementWeek::dueAt(
            $this->isBlocked() && $this->oldestUnpaidWeekOf !== null
                ? $this->oldestUnpaidWeekOf
                : SettlementWeek::current(),
        );
    }

    /**
     * Everything that ADDED to the debt in this week or later.
     */
    private static function addedSince(string $userId, DebtSide $side, string $weekOf): int
    {
        return (int) DebtEntry::query()
            ->where('user_id', $userId)
            ->where('side', $side->value)
            ->where('amount_piastres', '>', 0)
            ->where('week_of', '>=', $weekOf)
            ->sum('amount_piastres');
    }

    /**
     * Walks the additions newest first until they cover the balance: the line where that happens
     * is where the unpaid money starts (oldest-first payment). Late fees met on the way are what
     * is still owed of them.
     *
     * @return array{0: DebtEntry|null, 1: int}
     */
    private static function oldestUnpaid(string $userId, DebtSide $side, int $balance): array
    {
        if ($balance <= 0) {
            return [null, 0];
        }

        $covered = 0;
        $lateFees = 0;

        $additions = DebtEntry::query()
            ->where('user_id', $userId)
            ->where('side', $side->value)
            ->where('amount_piastres', '>', 0)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursor();

        $last = null;

        foreach ($additions as $line) {
            $last = $line;
            $part = min($line->amount_piastres, $balance - $covered);
            $covered += $part;

            if ($line->type === DebtEntryType::LateFee) {
                $lateFees += $part;
            }

            if ($covered >= $balance) {
                return [$line, $lateFees];
            }
        }

        return [$last, $lateFees];
    }
}
