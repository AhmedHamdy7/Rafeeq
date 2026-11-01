<?php

namespace App\Domains\Payment\Actions;

use App\Domains\Payment\Models\DebtAccount;
use App\Domains\Payment\Models\DebtEntry;
use Illuminate\Support\Facades\Log;

/**
 * Pitfall #39: the projection drifting from the ledger, checked every night.
 *
 * `debt_accounts.balance_piastres` is read everywhere (the Friday block, the debt screen, the
 * dashboard); `debt_entries` is the truth. They are written together by `DebtLedger`, so they
 * should never disagree — and this is how anybody would find out if they did.
 *
 * 🔴 It REPORTS, it does not repair. A drifted balance means a bug wrote money somewhere it should
 * not have, and silently overwriting the projection would erase the one symptom of it. Staff fix it
 * with an `adjustment` once somebody understands why.
 */
final readonly class ReconcileDebtAccountsAction
{
    /**
     * @return int how many accounts disagree with their ledger
     */
    public function execute(): int
    {
        $drifted = 0;

        $truth = DebtEntry::query()
            ->selectRaw('user_id, side, SUM(amount_piastres) as total')
            ->groupBy('user_id', 'side')
            ->get()
            ->mapWithKeys(fn ($row) => ["{$row->user_id}|{$row->side->value}" => (int) $row->total]);

        DebtAccount::query()->chunkById(500, function ($accounts) use ($truth, &$drifted): void {
            foreach ($accounts as $account) {
                $ledger = (int) ($truth["{$account->user_id}|{$account->side->value}"] ?? 0);

                if ($ledger !== $account->balance_piastres) {
                    $drifted++;

                    Log::critical('Debt account does not match its ledger', [
                        'user_id' => $account->user_id,
                        'side' => $account->side->value,
                        'projection_piastres' => $account->balance_piastres,
                        'ledger_piastres' => $ledger,
                    ]);

                    continue;
                }

                $account->forceFill(['reconciled_at' => now()])->save();
            }
        });

        return $drifted;
    }
}
