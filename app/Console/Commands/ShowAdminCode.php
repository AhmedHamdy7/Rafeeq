<?php

namespace App\Console\Commands;

use App\Domains\Admin\Models\AdminUser;
use Illuminate\Console\Command;
use PragmaRX\Google2FA\Google2FA;

/**
 * `php artisan rafeeq:admin-code` — the six-digit code the dashboard is asking for, right now.
 *
 * 🔴 Built because the alternative was a `tinker --execute` one-liner with nested quotes and
 * backslashes, retyped into a shell on a hosted console, under a thirty-second deadline before the
 * code rotates. It failed more often than it worked.
 *
 * 🔒 Deliberately unavailable in production, and deliberately a CONSOLE command rather than a
 * route. Printing a valid second factor to whoever can run it defeats the point of having one —
 * what makes that acceptable on a staging box is that the console is already authenticated and the
 * accounts there are seeded demo accounts. A real deployment enrols a secret once, with
 * `php artisan admin:create`, and this command refuses to run at all.
 */
final class ShowAdminCode extends Command
{
    protected $signature = 'rafeeq:admin-code {email? : Which staff account, if there is more than one}';

    protected $description = 'Print the current authenticator code for a staff account (non-production only).';

    public function handle(Google2FA $google2fa): int
    {
        if (app()->isProduction()) {
            $this->error('Refused: this prints a valid second factor, and will not do that in production.');

            return self::FAILURE;
        }

        $email = $this->argument('email');

        $admin = $email === null
            ? AdminUser::query()->oldest('created_at')->first()
            : AdminUser::query()->where('email', $email)->first();

        if ($admin === null) {
            $this->error($email === null
                ? 'No staff accounts exist. Seed the database, or run `php artisan admin:create`.'
                : "No staff account with the email [{$email}].");

            return self::FAILURE;
        }

        if ($admin->mfa_secret === null) {
            $this->error("[{$admin->email}] has no authenticator secret enrolled.");

            return self::FAILURE;
        }

        /*
         * The remaining validity, because the whole reason this command exists is that the code
         * rotates while somebody is typing it. Three seconds left is worth knowing before you
         * start rather than after you are refused.
         */
        $secondsLeft = 30 - (now()->timestamp % 30);

        $this->newLine();
        $this->info($google2fa->getCurrentOtp($admin->mfa_secret));
        $this->line("  for {$admin->email} · valid for {$secondsLeft}s");
        $this->newLine();

        if ($secondsLeft <= 5) {
            $this->warn('  That is about to rotate — run this again and use the next one.');
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
