<?php

namespace App\Console\Commands;

use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;

final class AddDemoDriverHomeStates extends Command
{
    protected $signature = 'demo:driver-home-states';

    protected $description = 'Create or reset one demo driver per Driver Home state, with departures set relative to now (never in production).';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('demo:driver-home-states changes demo commutes and is refused on APP_ENV=production.');

            return self::FAILURE;
        }

        foreach (app(DemoDataSeeder::class)->addDriverHomeStates() as $line) {
            $this->line("  {$line}");
        }

        return self::SUCCESS;
    }
}
