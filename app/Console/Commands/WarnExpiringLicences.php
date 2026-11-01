<?php

namespace App\Console\Commands;

use App\Domains\Driver\Actions\WarnExpiringLicencesAction;
use Illuminate\Console\Command;

/**
 * "Your driving licence expires soon" (Feature 03 files §8). Once per expiry date; see
 * {@see WarnExpiringLicencesAction}. If this does not run, the driver card still says "Expiring",
 * but nothing reaches her phone until the licence has lapsed and she can no longer drive.
 */
final class WarnExpiringLicences extends Command
{
    protected $signature = 'licences:warn-expiring';

    protected $description = 'Tell drivers whose licence expires inside the warning window';

    public function handle(WarnExpiringLicencesAction $action): int
    {
        $this->info(sprintf('Warned %d driver(s).', $action->sweep()));

        return self::SUCCESS;
    }
}
