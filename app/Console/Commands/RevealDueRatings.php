<?php

namespace App\Console\Commands;

use App\Domains\Rating\Actions\RevealRatingsAction;
use Illuminate\Console\Command;

/**
 * Reveals one-sided ratings whose window has run out (Bible §9: "when both rate, **or** seven days
 * pass").
 *
 * 🔴 This is the half that makes double-blind workable rather than a trap. If a reveal needed both
 * sides, a passenger who simply never rates would keep her driver's rating hidden for ever — and
 * the quietest way to suppress a review you expect to be bad would be to not write one. The window
 * is what stops silence from being a veto.
 *
 * ⚠️ Without a cron entry running `php artisan schedule:run`, nothing is ever revealed: every
 * one-sided rating stays invisible, averages never move, and the ratings feature looks broken in a
 * way no error reports. See DEPLOYMENT.md.
 */
final class RevealDueRatings extends Command
{
    protected $signature = 'ratings:reveal-due';

    protected $description = 'Reveal one-sided ratings whose window has passed (Bible §9).';

    public function handle(RevealRatingsAction $reveals): int
    {
        $revealed = $reveals->due();

        $this->info("Revealed {$revealed} ratings whose window had passed.");

        return self::SUCCESS;
    }
}
