<?php

namespace App\Domains\Admin\Actions;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Enums\AdminStatus;
use App\Domains\Admin\Models\AdminInvitation;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\AdminActionLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

/**
 * TEAM AND ROLES (M): invite staff, reset their access, change their role, suspend and
 * reactivate them. Only `admin.manage` (the Admin role) may — every method checks it.
 *
 * 🔒 The rules that keep the dashboard reachable and honest:
 * - Nobody changes their own role or status: a mistake there is a lock-out, and an
 *   Admin promoting or reinstating themselves is exactly what the audit log exists to stop.
 * - The last active Admin cannot be demoted or suspended.
 * - An invitation link is shown once, to the Admin who made it; only its hash is stored.
 *   Making a new one revokes the old ones for that person.
 * - Every step is one audit row: who did what to whom, before and after.
 */
final class ManageTeamAction
{
    public const int INVITATION_HOURS = 72;

    public function __construct(private Google2FA $google2fa) {}

    /**
     * @return array{admin: AdminUser, link: string}
     */
    public function invite(AdminUser $by, string $name, string $email, AdminRole $role): array
    {
        $this->authorize($by);

        $email = mb_strtolower(trim($email));

        if (AdminUser::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => __('admin/team.errors.email_taken')]);
        }

        return DB::transaction(function () use ($by, $name, $email, $role) {
            app(SyncAdminRolesAction::class)->execute();

            $admin = new AdminUser;
            $admin->forceFill([
                'name' => trim($name),
                'email' => $email,
                // Unusable until the invitation sets one: nobody knows this string.
                'password_hash' => Hash::make(Str::random(64)),
                'mfa_secret' => $this->google2fa->generateSecretKey(),
                'mfa_confirmed_at' => null,
                'status' => AdminStatus::Invited,
            ])->save();

            $admin->assignRole($role->value);

            AdminActionLog::record($by, 'admin.invite', $admin, null, ['email' => $email, 'role' => $role->value]);

            return ['admin' => $admin, 'link' => $this->issueLink($admin, $by)];
        });
    }

    /**
     * Lost phone, forgotten password, suspicion: a new password and a new authenticator,
     * through a fresh one-time link. Until it is used the account cannot sign in.
     */
    public function resetAccess(AdminUser $by, AdminUser $admin): string
    {
        $this->authorize($by);
        $this->refuseSelf($by, $admin);

        return DB::transaction(function () use ($by, $admin) {
            $before = ['status' => $admin->status->value];

            $admin->forceFill([
                'password_hash' => Hash::make(Str::random(64)),
                'mfa_secret' => $this->google2fa->generateSecretKey(),
                'mfa_confirmed_at' => null,
                'mfa_last_used_timestamp' => null,
                'status' => $admin->status === AdminStatus::Suspended ? AdminStatus::Suspended : AdminStatus::Invited,
            ])->save();

            AdminActionLog::record($by, 'admin.reset_access', $admin, $before, ['status' => $admin->status->value]);

            return $this->issueLink($admin, $by);
        });
    }

    public function changeRole(AdminUser $by, AdminUser $admin, AdminRole $role): void
    {
        $this->authorize($by);
        $this->refuseSelf($by, $admin);

        $from = $admin->getRoleNames()->first();

        if ($from === $role->value) {
            return;
        }

        if ($from === AdminRole::SuperAdmin->value) {
            $this->refuseLastAdmin($admin);
        }

        DB::transaction(function () use ($by, $admin, $role, $from) {
            app(SyncAdminRolesAction::class)->execute();
            $admin->syncRoles([$role->value]);

            AdminActionLog::record($by, 'admin.change_role', $admin, ['role' => $from], ['role' => $role->value]);
        });
    }

    public function suspend(AdminUser $by, AdminUser $admin): void
    {
        $this->authorize($by);
        $this->refuseSelf($by, $admin);

        if ($admin->getRoleNames()->first() === AdminRole::SuperAdmin->value) {
            $this->refuseLastAdmin($admin);
        }

        $this->setStatus($by, $admin, AdminStatus::Suspended, 'admin.suspend');
    }

    public function reactivate(AdminUser $by, AdminUser $admin): void
    {
        $this->authorize($by);
        $this->refuseSelf($by, $admin);

        // Back to where they were: active if they had finished the first sign-in, invited if not.
        $this->setStatus($by, $admin, $admin->hasMfaConfirmed() ? AdminStatus::Active : AdminStatus::Invited, 'admin.reactivate');
    }

    private function setStatus(AdminUser $by, AdminUser $admin, AdminStatus $to, string $action): void
    {
        $before = ['status' => $admin->status->value];
        $admin->forceFill(['status' => $to])->save();

        AdminActionLog::record($by, $action, $admin, $before, ['status' => $to->value]);
    }

    private function issueLink(AdminUser $admin, AdminUser $by): string
    {
        AdminInvitation::query()
            ->where('admin_user_id', $admin->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $token = Str::random(48);

        AdminInvitation::query()->create([
            'admin_user_id' => $admin->id,
            'token_hash' => AdminInvitation::hash($token),
            'invited_by_admin_id' => $by->id,
            'expires_at' => now()->addHours(self::INVITATION_HOURS),
        ]);

        return route('admin.invitation', $token);
    }

    private function authorize(AdminUser $by): void
    {
        if (! $by->can(AdminPermission::AdminManage->value)) {
            throw new AuthorizationException;
        }
    }

    private function refuseSelf(AdminUser $by, AdminUser $admin): void
    {
        if ($by->is($admin)) {
            throw ValidationException::withMessages(['team' => __('admin/team.errors.not_yourself')]);
        }
    }

    private function refuseLastAdmin(AdminUser $admin): void
    {
        $otherActiveAdmins = AdminUser::role(AdminRole::SuperAdmin->value, 'admin')
            ->where('status', AdminStatus::Active->value)
            ->whereKeyNot($admin->id)
            ->count();

        if ($otherActiveAdmins === 0) {
            throw ValidationException::withMessages(['team' => __('admin/team.errors.last_admin')]);
        }
    }
}
