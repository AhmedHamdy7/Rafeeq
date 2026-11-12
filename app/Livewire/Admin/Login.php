<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Actions\AuthenticateAdminAction;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\AdminMfa;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
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

    /**
     * Seconds until sign-in opens again after too many wrong attempts (screen S03), or
     * null when it is not locked. Display only: the lock itself is the rate limiter in
     * AuthenticateAdminAction, and a tampered value here unlocks nothing.
     */
    public ?int $lockedFor = null;

    public function submitPassword(AuthenticateAdminAction $action): void
    {
        $this->validate([
            'email' => ['required', 'email', 'max:150'],
            'password' => ['required', 'string'],
        ]);

        try {
            $admin = $action->verifyPassword($this->email, $this->password, request()->ip());
        } catch (DomainException $e) {
            $this->refuse($e, 'email');

            return;
        }

        $this->password = '';

        if (! AdminMfa::enabled()) {
            $action->completeWithoutMfa($admin, request()->ip());
            $this->signIn($admin);

            return;
        }

        $this->pendingAdminId = $admin->id;
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
            $this->refuse($e, 'code');

            return;
        }

        $this->signIn($admin);
    }

    /** Back to the email step once the lock has run out (the countdown calls this). */
    public function unlock(): void
    {
        $this->reset('lockedFor', 'pendingAdminId', 'code', 'password');
    }

    /**
     * One message for a wrong password and a wrong code alike (S02), so nobody learns
     * which half was right — and the lock screen (S03) when the limiter has had enough.
     */
    private function refuse(DomainException $e, string $field): void
    {
        $this->password = '';
        $this->addError($field, __('errors.'.$e->errorCode->value));

        if ($e->errorCode === ErrorCode::TooManyRequests) {
            $this->lockedFor = max(1, (int) ($e->headers['Retry-After'] ?? 60));
        }
    }

    private function signIn(AdminUser $admin): void
    {
        Auth::guard('admin')->login($admin);

        /*
         * Regenerated the moment privilege changes — without it, an id an attacker
         * planted in the visitor's browser beforehand becomes an authenticated admin
         * session (session fixation).
         */
        session()->regenerate();
        session()->put(EnsureAdminMfaIsConfirmed::PASSED, true);

        // The first page this admin's role can open — see HomeController.
        $this->redirectRoute('admin.home', navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.login')->title(__('admin/auth.sign_in.title'));
    }
}
