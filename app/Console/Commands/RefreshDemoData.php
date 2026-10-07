<?php

namespace App\Console\Commands;

use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;

/**
 * Keeps a demo/staging environment usable as the days pass: every demo passenger back on an
 * upcoming trip, and their saved requests matched against the commutes running now.
 *
 * Refused on APP_ENV=production — it books seats for named accounts.
 */
final class RefreshDemoData extends Command
{
    protected $signature = 'demo:refresh';

    protected $description = 'Put the demo testers back on an upcoming trip and refresh their matches (never in production).';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('demo:refresh books seats for demo accounts and is refused on APP_ENV=production.');

            return self::FAILURE;
        }

        foreach (app(DemoDataSeeder::class)->refreshTesters() as $row) {
            $this->line(sprintf('  %s  %s  %d new matches', $row['phone'], $row['booked'] ? 'seated on an upcoming trip' : 'already has an upcoming trip', $row['matches']));
        }

        $this->info('Demo data refreshed.');

        return self::SUCCESS;
    }
}
