<?php

use App\Domains\Admin\Actions\AuthenticateAdminAction;
use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Http\Middleware\EnsureAdminMfaIsConfirmed;
use App\Livewire\Admin\Login;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * 🔒 Staff sign-in.
 *
 * Worth more scrutiny than any member-facing flow, and for a concrete reason: one of
 * these accounts can read every identity document on the platform, approve a driver and
 * suspend anyone, and it is reachable from the internet with an email address.
 */
beforeEach(function () {
    // The limiter is cache-backed and therefore survives the database refresh between
    // tests; without this, a file of throttling tests starts failing partway through
    // for reasons unrelated to what is under test.
    Cache::clear();

    $this->admin = adminWithRole(AdminRole::Verification, ['email' => 'nour@rafeeq.test']);
});

it('will not sign anybody in on a password alone', function () {
    Livewire::test(Login::class)
        ->set('email', 'nour@rafeeq.test')
        ->set('password', 'password')
        ->call('submitPassword')
        ->assertHasNoErrors();

    // The password step moves to the code step and NOTHING else: no guard, no session.
    expect(Auth::guard('admin')->check())->toBeFalse();
});

it('signs in once the authenticator code is right', function () {
    Livewire::test(Login::class)
        ->set('email', 'nour@rafeeq.test')
        ->set('password', 'password')
        ->call('submitPassword')
        ->set('code', currentTotpCode($this->admin))
        ->call('submitCode')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.home'));

    expect(Auth::guard('admin')->id())->toBe($this->admin->id)
        ->and(session(EnsureAdminMfaIsConfirmed::PASSED))->toBeTrue();
});

it('records the sign-in in the immutable audit log', function () {
    Livewire::test(Login::class)
        ->set('email', 'nour@rafeeq.test')
        ->set('password', 'password')
        ->call('submitPassword')
        ->set('code', currentTotpCode($this->admin))
        ->call('submitCode');

    $entry = AdminAction::query()->where('action', 'admin.login')->sole();

    expect($entry->admin_id)->toBe($this->admin->id)
        // 🔒 Hashed, not stored. A staff member's home address is not something this
        // table needs in the clear either.
        ->and($entry->ip_hash)->not->toBeNull()
        ->and($entry->ip_hash)->not->toBe('127.0.0.1');

    // The log cannot be tidied afterwards by whoever is in it.
    expect(fn () => $entry->update(['action' => 'something.else']))
        ->toThrow(RuntimeException::class);
});

it('stamps the last login without storing the address', function () {
    Livewire::test(Login::class)
        ->set('email', 'nour@rafeeq.test')
        ->set('password', 'password')
        ->call('submitPassword')
        ->set('code', currentTotpCode($this->admin))
        ->call('submitCode');

    $this->admin->refresh();

    expect($this->admin->last_login_at)->not->toBeNull()
        ->and($this->admin->last_login_ip_hash)->not->toBeNull()
        ->and(strlen((string) $this->admin->last_login_ip_hash))->toBe(64);
});

/**
 * 🔴 TOTP's whole promise is that a code is spent once. Without a replay guard, a code
 * shoulder-surfed or captured from a proxy stays valid for the rest of its 30-second
 * step plus the window either side.
 */
it('refuses a code that has already been used', function () {
    $code = currentTotpCode($this->admin);

    Livewire::test(Login::class)
        ->set('email', 'nour@rafeeq.test')
        ->set('password', 'password')
        ->call('submitPassword')
        ->set('code', $code)
        ->call('submitCode')
        ->assertHasNoErrors();

    Auth::guard('admin')->logout();

    // The same six digits, still inside their time window.
    Livewire::test(Login::class)
        ->set('email', 'nour@rafeeq.test')
        ->set('password', 'password')
        ->call('submitPassword')
        ->set('code', $code)
        ->call('submitCode')
        ->assertHasErrors('code');

    expect(Auth::guard('admin')->check())->toBeFalse();
});

it('gives the same answer for a wrong password and a wrong code', function () {
    $wrongPassword = Livewire::test(Login::class)
        ->set('email', 'nour@rafeeq.test')
        ->set('password', 'not-the-password')
        ->call('submitPassword')
        ->errors()
        ->first('email');

    $wrongCode = Livewire::test(Login::class)
        ->set('email', 'nour@rafeeq.test')
        ->set('password', 'password')
        ->call('submitPassword')
        ->set('code', '000000')
        ->call('submitCode')
        ->errors()
        ->first('code');

    // Telling somebody their password was right is telling them half the answer.
    expect($wrongPassword)->toBe($wrongCode)
        ->and($wrongPassword)->toBe(__('errors.ADMIN_CREDENTIALS_INVALID'));
});

it('answers an unknown email exactly as it answers a wrong password', function () {
    $unknown = Livewire::test(Login::class)
        ->set('email', 'nobody@rafeeq.test')
        ->set('password', 'password')
        ->call('submitPassword')
        ->errors()
        ->first('email');

    expect($unknown)->toBe(__('errors.ADMIN_CREDENTIALS_INVALID'));
});

it('refuses a suspended admin without saying so', function () {
    $suspended = adminWithRole(AdminRole::Verification, [
        'email' => 'gone@rafeeq.test',
        'status' => 'suspended',
    ]);

    Livewire::test(Login::class)
        ->set('email', 'gone@rafeeq.test')
        ->set('password', 'password')
        ->call('submitPassword')
        ->assertHasErrors('email');

    expect(Auth::guard('admin')->check())->toBeFalse();
});

/**
 * An admin who never finished enrolling has a secret but has not proved they can
 * generate codes from it. Letting them through on the password alone would make MFA
 * optional in practice for exactly the accounts created in a hurry.
 */
it('refuses an admin who never confirmed their authenticator', function () {
    $pending = adminWithRole(AdminRole::Verification, [
        'email' => 'pending@rafeeq.test',
        'mfa_confirmed_at' => null,
    ]);

    expect(fn () => app(AuthenticateAdminAction::class)
        ->verifyPassword('pending@rafeeq.test', 'password', '127.0.0.1'))
        ->toThrow(DomainException::class);
});

it('throttles repeated failures', function () {
    foreach (range(1, 5) as $attempt) {
        Livewire::test(Login::class)
            ->set('email', 'nour@rafeeq.test')
            ->set('password', 'wrong')
            ->call('submitPassword');
    }

    // The sixth is refused before the password is even compared, and says when to
    // come back rather than leaving the client to guess.
    try {
        app(AuthenticateAdminAction::class)->verifyPassword('nour@rafeeq.test', 'password', '127.0.0.1');

        $this->fail('A sixth attempt was allowed.');
    } catch (DomainException $e) {
        expect($e->errorCode)->toBe(ErrorCode::TooManyRequests)
            ->and($e->headers)->toHaveKey('Retry-After');
    }
});

/**
 * 🔴 Session fixation: without regenerating, a session id an attacker planted in the
 * visitor's browser beforehand becomes an authenticated admin session.
 */
it('regenerates the session id when privilege changes', function () {
    $before = session()->getId();

    Livewire::test(Login::class)
        ->set('email', 'nour@rafeeq.test')
        ->set('password', 'password')
        ->call('submitPassword')
        ->set('code', currentTotpCode($this->admin))
        ->call('submitCode');

    expect(session()->getId())->not->toBe($before);
});

it('never leaves the password in the component state', function () {
    $component = Livewire::test(Login::class)
        ->set('email', 'nour@rafeeq.test')
        ->set('password', 'password')
        ->call('submitPassword');

    // A Livewire component's state survives in the browser between requests, and the
    // code step has no use for it.
    expect($component->get('password'))->toBe('');
});

/**
 * 🔴 Signing in used to land on the verification queue for everybody. A safety lead holds no
 * `verification.view`, so the desk that answers SOS alerts got a bare 403 as its first screen.
 */
it('lands each role on the first page it can open', function (AdminRole $role, string $route) {
    actingAsAdmin(adminWithRole($role));

    $this->get(route('admin.home'))->assertRedirect(route($route));
})->with([
    'safety lead' => [AdminRole::SafetyLead, 'admin.safety'],
    'super admin' => [AdminRole::SuperAdmin, 'admin.safety'],
    'operations' => [AdminRole::Operations, 'admin.trips'],
    'verification' => [AdminRole::Verification, 'admin.verifications'],
    'support' => [AdminRole::Support, 'admin.verifications'],
]);

it('says plainly when a role has no page yet, instead of bouncing it to one that refuses', function () {
    actingAsAdmin(adminWithRole(AdminRole::Finance));

    $this->get(route('admin.home'))->assertForbidden();
});

it('sends a signed-out visitor at the bare dashboard address to the sign-in page', function () {
    $this->get('/admin')->assertRedirect(route('admin.login'));
});
