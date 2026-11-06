<?php

namespace App\Console\Commands;

use App\Domains\Driver\Actions\WarnExpiringRegistrationsAction;
use Illuminate\Console\Command;

final class WarnExpiringRegistrations extends Command
{
    protected $signature = 'registrations:warn-expiring';

    protected $description = 'Warn drivers whose car registration expires within 30 days';

    public function handle(WarnExpiringRegistrationsAction $action): int
    {
        $this->info(sprintf('Warned %d driver(s).', $action->sweep()));

        return self::SUCCESS;
    }
}
