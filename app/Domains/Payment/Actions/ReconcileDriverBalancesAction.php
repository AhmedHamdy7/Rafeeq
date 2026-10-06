<?php

namespace App\Domains\Payment\Actions;

use App\Domains\Payment\Models\DriverBalance;
use App\Domains\Payment\Models\DriverFeeLedger;
use Illuminate\Support\Facades\Log;

/**
 * Pitfall #39: the projection drifting from the ledger, checked every night.
 *
 * `driver_balances.outstanding_fee_piastres` is read everywhere (the debt cap, the driver's screen,
 * the dashboard); `driver_fee_ledger` is the truth. They are written together by one class, so they
 * should never disagree — and this is how anybody would find out if they did.
 *
 * 🔴 It REPORTS, it does not repair. A drifted balance means a bug wrote money somewhere it should
 * not have, and silently overwriting the projection would erase the one symptom of it. Staff fix it
 * with an `adjustment` once somebody understands why.
 */
final readonly class ReconcileDriverBalancesAction
{
    /**
     * @return int how many balances disagree with their ledger
     */
    public function execute(): int
    {
        $drifted = 0;

        $truth = DriverFeeLedger::query()
            ->selectRaw('driver_profile_id, SUM(amount_piastres) as total')
            ->groupBy('driver_profile_id')
            ->pluck('total', 'driver_profile_id');

        DriverBalance::query()->chunkById(500, function ($balances) use ($truth, &$drifted): void {
            foreach ($balances as $balance) {
                $ledger = (int) ($truth[$balance->driver_profile_id] ?? 0);

                if ($ledger !== $balance->outstanding_fee_piastres) {
                    $drifted++;

                    Log::critical('Driver fee balance does not match its ledger', [
                        'driver_profile_id' => $balance->driver_profile_id,
                        'projection_piastres' => $balance->outstanding_fee_piastres,
                        'ledger_piastres' => $ledger,
                    ]);

                    continue;
                }

                $balance->forceFill(['reconciled_at' => now()])->save();
            }
        }, 'driver_profile_id');

        return $drifted;
    }
}
