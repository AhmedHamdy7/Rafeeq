<?php

namespace App\Console\Commands;

use Database\Seeders\DriverRequestsStatesSeeder;
use Illuminate\Console\Command;

final class AddDemoDriverRequestsStates extends Command
{
    protected $signature = 'demo:driver-requests-states {--state= : One state to reset, for example REQ-INBOX}';

    protected $description = 'Create or reset one demo driver per Driver Requests state (never in production).';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('demo:driver-requests-states changes demo commutes and is refused on APP_ENV=production.');

            return self::FAILURE;
        }

        $state = $this->option('state');

        if ($state !== null && ! isset(DriverRequestsStatesSeeder::REQUEST_STATES[$state])) {
            $this->error("Unknown state {$state}. States: ".implode(', ', array_keys(DriverRequestsStatesSeeder::REQUEST_STATES)));

            return self::FAILURE;
        }

        foreach (app(DriverRequestsStatesSeeder::class)->applyRequestStates($state) as $line) {
            $this->line("  {$line}");
        }

        return self::SUCCESS;
    }
}
