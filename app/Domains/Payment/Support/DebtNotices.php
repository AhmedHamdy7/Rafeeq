<?php

namespace App\Domains\Payment\Support;

use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;

/**
 * The one place a debt notice is written, so every one of them speaks in pounds.
 *
 * 🔴 Amounts in the ledger are piastres; a lock screen never shows one. `:amount` (and `:fee`) are
 * always EGP, formatted here: "110", or "110.50" when there are piastres.
 */
final class DebtNotices
{
    public static function egp(int $piastres): string
    {
        $piastres = abs($piastres);

        return $piastres % 100 === 0
            ? (string) intdiv($piastres, 100)
            : number_format($piastres / 100, 2, '.', '');
    }

    /**
     * @param  array<string, int>  $amountsPiastres  e.g. ['amount' => 19_800, 'fee' => 1_800]
     */
    public static function send(User|string $user, NotificationType $type, array $amountsPiastres = []): void
    {
        $user = $user instanceof User ? $user : User::query()->find($user);

        if ($user === null) {
            return;
        }

        Notifier::send($user, $type, array_map(fn (int $p): string => self::egp($p), $amountsPiastres));
    }
}
