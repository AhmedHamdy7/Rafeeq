<?php

namespace App\Console\Commands;

use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;

final class AddDemoMatchStates extends Command
{
    protected $signature = 'demo:match-states';

    protected $description = 'Add suggested points, rules, a full commute and a man tester to an existing demo environment (never in production).';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('demo:match-states changes demo commutes and is refused on APP_ENV=production.');

            return self::FAILURE;
        }

        foreach (app(DemoDataSeeder::class)->addMatchStates() as $line) {
            $this->line("  {$line}");
        }

        $this->info('Demo match states added.');

        return self::SUCCESS;
    }
}
