<?php

namespace App\Console\Commands;

use App\Domains\Driver\Actions\StopExpiredLicencesAction;
use Illuminate\Console\Command;

final class StopExpiredLicences extends Command
{
    protected $signature = 'licences:stop-expired';

    protected $description = 'Stop drivers whose licence has expired: pause their commutes and cancel upcoming seats (COM-26, PROF-20)';

    public function handle(StopExpiredLicencesAction $action): int
    {
        $this->info(sprintf('Stopped %d driver(s).', $action->sweep()));

        return self::SUCCESS;
    }
}
