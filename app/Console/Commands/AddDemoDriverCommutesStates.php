<?php

namespace App\Console\Commands;

use Database\Seeders\DriverCommutesStatesSeeder;
use Illuminate\Console\Command;

final class AddDemoDriverCommutesStates extends Command
{
    protected $signature = 'demo:driver-commutes-states {--state= : One state to reset, for example DC-PASSENGERS}';

    protected $description = 'Create or reset one demo driver per Driver Commutes state (never in production).';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('demo:driver-commutes-states changes demo commutes and is refused on APP_ENV=production.');

            return self::FAILURE;
        }

        $state = $this->option('state');

        if ($state !== null && ! isset(DriverCommutesStatesSeeder::COMMUTE_STATES[$state])) {
            $this->error("Unknown state {$state}. States: ".implode(', ', array_keys(DriverCommutesStatesSeeder::COMMUTE_STATES)));

            return self::FAILURE;
        }

        foreach (app(DriverCommutesStatesSeeder::class)->applyCommuteStates($state) as $line) {
            $this->line("  {$line}");
        }

        return self::SUCCESS;
    }
}
