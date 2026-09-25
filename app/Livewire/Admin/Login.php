<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Actions\AuthenticateAdminAction;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Shared\Exceptions\DomainException;
use App\Http\Middleware\EnsureAdminMfaIsConfirmed;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Staff sign-in: password, then the authenticator code. Two steps in one component
 * because it is one conversation, and the browser holds nothing but a pending id
 * between them.
 *
 * 🔒 What is deliberately NOT in this class: any decision about whether the credentials
 * are good. That lives in {@see AuthenticateAdminAction} — the throttling, the constant
 * -time password comparison, the replay guard on the TOTP code — so it can be tested
 * without a browser and so a second entry point (an SSO callback, a console command)
 * cannot get a weaker version of the same checks.
 *
 * `$pendingAdminId` is the id only, kept in the component's state and therefore in the
 * signed Livewire payload. Not the AdminUser, and certainly not a "password was
 * correct" boolean that a tampered payload could set: possession of the id proves
 * nothing, and step two re-loads the record and re-checks everything about it.
 */
#[Layout('components.layouts.admin-guest')]
class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public string $code = '';

    /** Set once the password step has passed. Null means we are still on step one. */
    public ?string $pendingAdminId = null;

    public function submitPassword(AuthenticateAdminAction $action): void
    {
        $this->validate([
            'email' => ['required', 'email', 'max:150'],
            'password' => ['required', 'string'],
        ]);

        try {
            $admin = $action->verifyPassword($this->email, $this->password, request()->ip());
        } catch (DomainException $e) {
            $this->addError('email', __('errors.'.$e->errorCode->value));

            return;
        }

        $this->pendingAdminId = $admin->id;

        // Cleared immediately: there is no step that needs it again, and a Livewire
        // component's state survives in the browser between requests.
        $this->password = '';
    }

    public function submitCode(AuthenticateAdminAction $action): void
    {
        $this->validate([
            // Six digits, and a string so a numeric cast cannot drop a leading zero.
            'code' => ['required', 'string', 'digits:6'],
        ]);

        $admin = AdminUser::query()->find($this->pendingAdminId);

        if ($admin === null) {
            // The challenge no longer refers to a real account. Start over rather than
            // reasoning about why.
            $this->reset();

            return;
        }

        try {
            $action->verifyMfaCode($admin, $this->code, request()->ip());
        } catch (DomainException $e) {
            $this->code = '';
            $this->addError('code', __('errors.'.$e->errorCode->value));

            return;
        }

        Auth::guard('admin')->login($admin);

        /*
         * Regenerated the moment privilege changes — without it, an id an attacker
         * planted in the visitor's browser beforehand becomes an authenticated admin
         * session (session fixation).
         */
        session()->regenerate();
        session()->put(EnsureAdminMfaIsConfirmed::PASSED, true);

        $this->redirectRoute('admin.verifications', navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.login');
    }
}
