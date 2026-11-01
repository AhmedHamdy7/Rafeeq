<?php

namespace App\Console\Commands;

use Database\Seeders\ReviewAccountsSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * `rafeeq:review-accounts` — builds the account the store reviewers sign into (F1-1).
 *
 * Allowed in production, unlike `demo:refresh`: the review build talks to production. It only ever
 * creates the two fictional accounts in {@see ReviewAccountsSeeder} and leaves them alone once they
 * exist, so it is safe to run on every deploy.
 */
final class SeedReviewAccounts extends Command
{
    protected $signature = 'rafeeq:review-accounts';

    protected $description = 'Create the store-review account (RAFEEQ_REVIEW_PHONE) and a driver for it to find. Idempotent.';

    public function handle(): int
    {
        try {
            // Unguarded the way `db:seed` runs every seeder: the helpers it reuses fill
            // non-fillable columns (status, route, published_at) on purpose.
            Model::unguarded(fn () => app(ReviewAccountsSeeder::class)->setCommand($this)->run());
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
