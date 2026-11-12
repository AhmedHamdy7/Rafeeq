<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Actions\AcceptAdminInvitationAction;
use App\Domains\Admin\Actions\ChangeAdminPasswordAction;
use App\Domains\Admin\Models\AdminInvitation;
use App\Domains\Admin\Support\AdminMfa;
use App\Http\Middleware\EnsureAdminMfaIsConfirmed;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use PragmaRX\Google2FA\Google2FA;

/**
 * The first sign-in from an invitation link (S05 → S06): choose a password, then add the
 * authenticator and prove it with a code. Nothing is saved until the code is right — the
 * password waits in this component, and is checked again on the server when it is.
 *
 * `$token` is #[Locked]: the browser cannot swap the invitation under a page it already has.
 */
#[Layout('components.layouts.admin-guest')]
class AcceptInvitation extends Component
{
    #[Locked]
    public string $token = '';

    public int $step = 1;

    public string $password = '';

    public string $password_confirmation = '';

    public string $code = '';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function choosePassword(): void
    {
        $this->validate([
            'password' => ['required', 'string', 'min:'.ChangeAdminPasswordAction::MIN_LENGTH, 'max:200', 'confirmed'],
        ], attributes: ['password' => __('admin/account.password.new')]);

        if (! AdminMfa::enabled()) {
            $this->finish(app(AcceptAdminInvitationAction::class));

            return;
        }

        $this->step = 2;
    }

    public function back(): void
    {
        $this->step = 1;
        $this->reset('code');
    }

    public function finish(AcceptAdminInvitationAction $action): void
    {
        $invitation = AdminInvitation::findUsable($this->token);
        abort_if($invitation === null, 410);

        // The password is checked again here, not trusted from step one.
        $this->validate([
            'password' => ['required', 'string', 'min:'.ChangeAdminPasswordAction::MIN_LENGTH, 'max:200', 'confirmed'],
            'code' => AdminMfa::enabled() ? ['required', 'string', 'digits:6'] : ['nullable'],
        ]);

        $admin = $action->accept($invitation, $this->password, $this->code);

        // Whoever was signed in in this browser is not the person who just joined.
        Auth::guard('admin')->logout();
        Auth::guard('admin')->login($admin);
        session()->regenerate();
        session()->put(EnsureAdminMfaIsConfirmed::PASSED, true);

        $this->redirectRoute('admin.home', navigate: true);
    }

    public function render(Google2FA $google2fa)
    {
        $invitation = AdminInvitation::findUsable($this->token);
        $admin = $invitation?->admin;

        return view('livewire.admin.accept-invitation', [
            'invitation' => $invitation,
            'admin' => $admin,
            'role' => $admin?->getRoleNames()->first(),
            'invitedBy' => $invitation?->invitedBy?->name,
            // The key, grouped by four for reading aloud, and the link an authenticator app opens.
            'secret' => $admin && $this->step === 2 ? trim(chunk_split($admin->mfa_secret, 4, ' ')) : null,
            'otpauth' => $admin && $this->step === 2 ? $google2fa->getQRCodeUrl((string) config('app.name'), $admin->email, $admin->mfa_secret) : null,
        ])->title(__('admin/team.invitation.title'));
    }
}
