<?php

namespace App\Console\Commands;

use App\Domains\Admin\Actions\SyncAdminRolesAction;
use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Enums\AdminStatus;
use App\Domains\Admin\Models\AdminUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use PragmaRX\Google2FA\Google2FA;

/**
 * Creates a staff account from the console.
 *
 * This exists because of a chicken-and-egg problem with no other solution: the
 * dashboard's only way in is an admin account, admin accounts can only be created by a
 * super admin, and on a fresh deployment there is no super admin. Somebody with shell
 * access has to make the first one.
 *
 * 🔒 Console-only on purpose. There is no endpoint and no page that creates an admin,
 * so the ability to mint one is bounded by who can reach the server — not by whoever
 * finds a form. Creating further admins from inside the dashboard belongs with
 * `admin.manage`, which only Super Admin holds.
 *
 * The password is never taken as an argument. Arguments land in shell history, in
 * `ps` output while the command runs, and in any log that records commands — so it is
 * prompted for, hidden, and confirmed.
 */
final class CreateAdminUser extends Command
{
    protected $signature = 'admin:create
        {--email= : Work email address}
        {--name= : Display name}
        {--role= : One of the AdminRole values, e.g. verification}';

    protected $description = 'Create a staff account for the admin dashboard, with an MFA secret to enrol.';

    public function handle(SyncAdminRolesAction $syncRoles, Google2FA $google2fa): int
    {
        // Roles must exist before one can be assigned, and on a fresh database they
        // do not. Syncing here makes the command work on its own rather than failing
        // with a Spatie exception about a missing role.
        $syncRoles->execute();

        $email = $this->option('email') ?: $this->ask('Work email');
        $name = $this->option('name') ?: $this->ask('Display name');

        $role = $this->option('role') ?: $this->choice(
            'Role',
            array_map(fn (AdminRole $r) => $r->value, AdminRole::cases()),
        );

        $password = $this->secret('Password');

        if ($password !== $this->secret('Confirm password')) {
            $this->error('The passwords did not match.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'role' => $role, 'password' => $password],
            [
                'email' => ['required', 'email', 'max:150', 'unique:admin_users,email'],
                'name' => ['required', 'string', 'max:150'],
                'role' => ['required', 'string', 'in:'.implode(',', array_map(
                    fn (AdminRole $r) => $r->value,
                    AdminRole::cases(),
                ))],
                /*
                 * Stricter than a member's credential, because this one can read every
                 * identity document on the platform. `uncompromised()` checks the
                 * password against known breach corpora — the single cheapest thing
                 * that stops a staff account being opened with a password already in
                 * every wordlist.
                 */
                'password' => ['required', Password::min(12)->mixedCase()->numbers()->uncompromised()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $secret = $google2fa->generateSecretKey();

        $admin = new AdminUser;

        $admin->fill(['name' => $name, 'email' => $email]);
        $admin->password_hash = Hash::make($password);
        $admin->mfa_secret = $secret;
        $admin->status = AdminStatus::Active->value;
        /*
         * NOT confirmed. The account cannot sign in until somebody proves they can
         * generate codes from this secret — which is what `mfa_confirmed_at` means and
         * why `AuthenticateAdminAction` refuses an unenrolled admin. Marking it here
         * would make MFA a formality for exactly the accounts created in a hurry.
         */
        $admin->mfa_confirmed_at = null;
        $admin->save();

        $admin->assignRole($role);

        $this->newLine();
        $this->info("Created {$email} as {$role}.");
        $this->newLine();
        $this->warn('Enrol this secret in an authenticator app now — it is not shown again:');
        $this->line("  {$secret}");
        $this->newLine();
        $this->comment('Then confirm enrolment (sets mfa_confirmed_at) before first sign-in.');

        return self::SUCCESS;
    }
}
