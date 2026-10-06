<?php

namespace App\Console\Commands;

use App\Domains\Payment\Actions\SettleCashBookingsAction;
use Illuminate\Console\Command;

final class SettleCashBookings extends Command
{
    protected $signature = 'payments:settle-cash';

    protected $description = 'Record cash collected on trips whose attendance was confirmed, undisputed, over the collection delay (Bible §8.1).';

    public function handle(SettleCashBookingsAction $action): int
    {
        $settled = $action->execute();

        $this->info("Settled {$settled} cash bookings.");

        return self::SUCCESS;
    }
}
