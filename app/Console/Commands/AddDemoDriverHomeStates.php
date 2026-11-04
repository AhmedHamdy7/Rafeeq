<?php

namespace App\Console\Commands;

use Database\Seeders\DriverHomeStatesSeeder;
use Illuminate\Console\Command;

final class AddDemoDriverHomeStates extends Command
{
    protected $signature = 'demo:driver-home-states {--state= : One state to reset, for example F3D-05} {--phone= : That state\'s phone number, as a check}';

    protected $description = 'Create or reset one demo driver per Driver Home state, with departures set relative to now (never in production).';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('demo:driver-home-states changes demo commutes and is refused on APP_ENV=production.');

            return self::FAILURE;
        }

        $state = $this->option('state');
        $phone = $this->option('phone');
        $states = DriverHomeStatesSeeder::STATES;

        if ($state === null && $phone !== null) {
            $this->error('--phone needs --state.');

            return self::FAILURE;
        }

        if ($state !== null && ! isset($states[$state])) {
            $this->error("Unknown state {$state}. States: ".implode(', ', array_keys($states)));

            return self::FAILURE;
        }

        if ($state !== null && $phone !== null && $states[$state][0] !== $phone) {
            $this->error("{$state} belongs to {$states[$state][0]}, not {$phone}.");

            return self::FAILURE;
        }

        foreach (app(DriverHomeStatesSeeder::class)->apply($state) as $line) {
            $this->line("  {$line}");
        }

        return self::SUCCESS;
    }
}
