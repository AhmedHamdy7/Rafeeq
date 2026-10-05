<?php

namespace App\Console\Commands;

use App\Domains\Shared\Actions\PurgeExpiredFilesAction;
use Illuminate\Console\Command;

final class PurgeExpiredFiles extends Command
{
    protected $signature = 'files:purge-expired';

    protected $description = 'Destroy identity documents, vehicle documents and report evidence past their retention date (ERD §18).';

    public function handle(PurgeExpiredFilesAction $action): int
    {
        $purged = $action->execute();

        $this->info("Purged {$purged['documents']} identity documents, {$purged['vehicleDocuments']} vehicle documents, {$purged['evidence']} evidence files.");

        return self::SUCCESS;
    }
}
