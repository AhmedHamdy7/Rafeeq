<?php

namespace App\Domains\Payment\Actions;

use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtAccount;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Payment\Support\DebtLedger;
use App\Domains\Payment\Support\DebtNotices;
use App\Domains\Payment\Support\DebtSettings;
use App\Domains\Payment\Support\DebtStanding;
use App\Domains\Payment\Support\SettlementWeek;

/**
 * The weekly cycle, run every hour (PAY-11 → PAY-17, PAY-45; Feature 03 backend file L-5 → L-7).
 *
 * For every account that owes something:
 *
 * | step              | when                                               | rule   |
 * |-------------------|----------------------------------------------------|--------|
 * | late fee          | last week's Friday passed with part of it unpaid    | PAY-13 |
 * | suspend (driver)  | money from a week that has missed its 2nd Friday    | PAY-45 |
 * | suspend (rider)   | a fee unpaid 14 days, or over 200 EGP               | PAY-17 |
 * | one reminder/day  | from `debt.reminder_hour` Cairo (the late fee at once), driver side only | PAY-11, PAY-12 |
 *
 * 🔴 Safe to run as often as you like. The late fee is keyed once per driver per week; a
 * suspension finds its own mark; the reminder stamps `last_reminded_on`. Hourly rather than once a
 * day so a missed run costs an hour, not a day — and so the reminder hour can be a setting.
 */
final readonly class SweepDebtsAction
{
    public function __construct(private SuspendForDebtAction $suspend) {}

    /**
     * @return array{lateFees: int, suspended: int, reminded: int}
     */
    public function execute(): array
    {
        $done = ['lateFees' => 0, 'suspended' => 0, 'reminded' => 0];

        DebtAccount::query()
            ->where('balance_piastres', '>', 0)
            ->orderBy('id')
            ->chunkById(200, function ($accounts) use (&$done): void {
                foreach ($accounts as $account) {
                    $account->side === DebtSide::Driver
                        ? $this->driver($account, $done)
                        : $this->passenger($account, $done);
                }
            });

        return $done;
    }

    /**
     * @param  array{lateFees: int, suspended: int, reminded: int}  $done
     */
    private function driver(DebtAccount $account, array &$done): void
    {
        $userId = $account->user_id;
        $lateFee = $account->suspended_at === null ? $this->lateFee($userId) : null;

        if ($lateFee !== null) {
            $done['lateFees']++;
        }

        if ($this->suspend->driverIfDue($userId)) {
            $done['suspended']++;

            return;
        }

        $account->refresh();

        // The late fee is news the day it lands, whatever the hour; everything else waits for the
        // reminder hour, and nothing goes out twice in one day.
        if ($account->suspended_at !== null || ($lateFee === null && ! $this->reminderIsDue($account))) {
            return;
        }

        $standing = DebtStanding::of($userId, DebtSide::Driver);
        $type = $standing->owedPiastres() > 0 ? $this->reminderFor($standing, $lateFee) : null;

        if ($type === null) {
            return;
        }

        DebtNotices::send($userId, $type, array_filter([
            'amount' => $standing->owedPiastres(),
            'fee' => $lateFee?->amount_piastres,
        ], fn ($v) => $v !== null));

        $account->forceFill(['last_reminded_on' => $this->today()])->save();
        $done['reminded']++;
    }

    /**
     * @param  array{lateFees: int, suspended: int, reminded: int}  $done
     */
    private function passenger(DebtAccount $account, array &$done): void
    {
        // The 14 days pass with nothing written to her ledger, so the sweep is what notices them.
        if ($this->suspend->passengerIfDue($account->user_id)) {
            $done['suspended']++;
        }
    }

    /**
     * PAY-13: +10 % once on what is still unpaid from LAST week, once its Friday has passed.
     *
     * Its base is that week's share of the unpaid money only — earlier weeks (and their late fees)
     * are left out, so a late fee never earns a late fee. The line is written INTO that week, so it
     * is due at once ("180 → 198") and ages with the week it belongs to.
     *
     * Returns the line when one was written now.
     */
    private function lateFee(string $userId): ?DebtEntry
    {
        $percent = DebtSettings::lateFeePercent();
        $week = SettlementWeek::shift(SettlementWeek::current(), -1);
        $key = "late_fee:{$userId}:{$week}";

        if ($percent === 0 || DebtEntry::query()->where('idempotency_key', $key)->exists()) {
            return null;
        }

        $balance = DebtLedger::balanceOf($userId, DebtSide::Driver);
        $unpaidUpToWeek = max(0, $balance - $this->addedSince($userId, SettlementWeek::shift($week, 1)));
        $unpaidBeforeWeek = max(0, $balance - $this->addedSince($userId, $week));
        $base = $unpaidUpToWeek - $unpaidBeforeWeek;

        $fee = intdiv($base * $percent, 100);

        if ($fee <= 0) {
            return null;
        }

        return DebtLedger::record(
            $userId,
            DebtSide::Driver,
            DebtEntryType::LateFee,
            $fee,
            'paid_after_friday',
            meta: ['rule' => 'PAY-13', 'basePiastres' => $base, 'percent' => $percent],
            idempotencyKey: $key,
            weekOf: $week,
        );
    }

    /**
     * Which reminder today, if any: the late fee the day it is added; on Thursday the warning (or,
     * when nothing is late, "due tomorrow"); every other day while something is late, "overdue".
     */
    private function reminderFor(DebtStanding $standing, ?DebtEntry $lateFee): ?NotificationType
    {
        if ($lateFee !== null) {
            return NotificationType::DebtLateFeeAdded;
        }

        $thursday = now()->setTimezone(SettlementWeek::TIMEZONE)->isThursday();

        if ($standing->isBlocked()) {
            return $thursday ? NotificationType::DebtSuspensionWarning : NotificationType::DebtOverdue;
        }

        return $thursday ? NotificationType::DebtDueTomorrow : null;
    }

    private function reminderIsDue(DebtAccount $account): bool
    {
        $cairo = now()->setTimezone(SettlementWeek::TIMEZONE);

        return $cairo->hour >= DebtSettings::reminderHour()
            && $account->last_reminded_on?->toDateString() !== $this->today();
    }

    private function today(): string
    {
        return now()->setTimezone(SettlementWeek::TIMEZONE)->toDateString();
    }

    private function addedSince(string $userId, string $weekOf): int
    {
        return (int) DebtEntry::query()
            ->where('user_id', $userId)
            ->where('side', DebtSide::Driver->value)
            ->where('amount_piastres', '>', 0)
            ->where('week_of', '>=', $weekOf)
            ->sum('amount_piastres');
    }
}
