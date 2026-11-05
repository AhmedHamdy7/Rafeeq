<?php

namespace App\Console\Commands;

use Database\Seeders\CreateCommuteStatesSeeder;
use Illuminate\Console\Command;

final class AddDemoCreateCommuteStates extends Command
{
    protected $signature = 'demo:create-commute-states {--state= : One state to reset, for example CC-READY}';

    protected $description = 'Create or reset one demo driver per Create Commute state (never in production).';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('demo:create-commute-states changes demo commutes and is refused on APP_ENV=production.');

            return self::FAILURE;
        }

        $state = $this->option('state');

        if ($state !== null && ! isset(CreateCommuteStatesSeeder::CREATE_STATES[$state])) {
            $this->error("Unknown state {$state}. States: ".implode(', ', array_keys(CreateCommuteStatesSeeder::CREATE_STATES)));

            return self::FAILURE;
        }

        foreach (app(CreateCommuteStatesSeeder::class)->applyCreateStates($state) as $line) {
            $this->line("  {$line}");
        }

        return self::SUCCESS;
    }
}
