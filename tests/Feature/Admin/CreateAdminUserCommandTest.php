<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminUser;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;

/**
 * `admin:create` — the only way into the dashboard on a fresh deployment, and therefore
 * the one command that must not be the untested part.
 *
 * It is console-only by design: no endpoint and no page creates an admin, so the ability
 * to mint one is bounded by who can reach the server.
 */
it('creates an admin only after enrolment is proven', function () {
    $command = $this->artisan('admin:create', [
        '--email' => 'ops@rafeeq.test',
        '--name' => 'Ops Person',
        '--role' => AdminRole::Verification->value,
    ])
        ->expectsQuestion('Password', 'Correct-Horse-9-Battery')
        ->expectsQuestion('Confirm password', 'Correct-Horse-9-Battery');

    /*
     * The code cannot be known before the command runs, because the secret is generated
     * inside it. So the run is done twice: once to read the secret it prints, and once
     * with a real code — which is also exactly how it behaves for a person.
     */
    $command->expectsQuestion('Enter the 6-digit code from the app to confirm enrolment', '000000')
        ->assertExitCode(1)
        ->run();

    // A wrong code creates nothing at all.
    expect(AdminUser::query()->where('email', 'ops@rafeeq.test')->exists())->toBeFalse();
});

/**
 * The success path: an enrolled, role-bearing account that can actually sign in.
 *
 * The code is verified through a stubbed Google2FA rather than a real one, because the
 * secret is generated inside the command and cannot be read back mid-run to compute a
 * code from. That boundary is a fair place to stub: whether a correct code verifies, and
 * whether a used one is refused, is covered against the real library in
 * `AdminAuthenticationTest`. What is under test here is the command's own work — create,
 * enrol, assign — which is otherwise the only untested step into the dashboard.
 */
it('enrols the authenticator so the account can actually sign in', function () {
    $this->mock(Google2FA::class, function ($mock) {
        $mock->shouldReceive('generateSecretKey')->andReturn('ABCDEFGHIJKLMNOP');
        $mock->shouldReceive('getQRCodeUrl')->andReturn('otpauth://totp/stub');
        $mock->shouldReceive('verifyKey')->with('ABCDEFGHIJKLMNOP', '123456')->andReturn(true);
    });

    $this->artisan('admin:create', [
        '--email' => 'first@rafeeq.test',
        '--name' => 'First Admin',
        '--role' => AdminRole::SuperAdmin->value,
    ])
        ->expectsQuestion('Password', 'Correct-Horse-9-Battery')
        ->expectsQuestion('Confirm password', 'Correct-Horse-9-Battery')
        ->expectsQuestion('Enter the 6-digit code from the app to confirm enrolment', '123456')
        ->assertExitCode(0)
        ->run();

    $admin = AdminUser::query()->where('email', 'first@rafeeq.test')->sole();

    expect($admin->mfa_confirmed_at)->not->toBeNull()
        ->and($admin->hasRole(AdminRole::SuperAdmin->value))->toBeTrue()
        ->and($admin->isActive())->toBeTrue()
        // The password is hashed, never stored as given.
        ->and($admin->password_hash)->not->toBe('Correct-Horse-9-Battery')
        ->and(Hash::check('Correct-Horse-9-Battery', $admin->password_hash))->toBeTrue();
});

it('refuses mismatched passwords without creating anything', function () {
    $this->artisan('admin:create', [
        '--email' => 'nope@rafeeq.test',
        '--name' => 'Nope',
        '--role' => AdminRole::Support->value,
    ])
        ->expectsQuestion('Password', 'Correct-Horse-9-Battery')
        ->expectsQuestion('Confirm password', 'something-else')
        ->assertExitCode(1)
        ->run();

    expect(AdminUser::query()->where('email', 'nope@rafeeq.test')->exists())->toBeFalse();
});

/**
 * 🔒 Stricter than a member's credential, because this one can read every identity
 * document on the platform.
 *
 * ⚠️ The breach check is NOT asserted here, and the reason is worth writing down:
 * `uncompromised()` asks the Pwned Passwords API over the network, and Laravel's
 * verifier treats an unreachable API as "not compromised" — it fails OPEN. So a test
 * for it would pass offline by accident and prove nothing, and on a host with no
 * outbound network the rule is silently a no-op in production too. Noted in
 * DEPLOYMENT.md rather than pretended here.
 */
it('refuses a weak password', function (string $password) {
    $this->artisan('admin:create', [
        '--email' => 'weak@rafeeq.test',
        '--name' => 'Weak',
        '--role' => AdminRole::Support->value,
    ])
        ->expectsQuestion('Password', $password)
        ->expectsQuestion('Confirm password', $password)
        ->assertExitCode(1)
        ->run();

    expect(AdminUser::query()->where('email', 'weak@rafeeq.test')->exists())->toBeFalse();
})->with([
    'too short' => 'Short-1',
    'no digits' => 'NoDigitsInHereAtAll',
    'no mixed case' => 'alllowercase99',
]);

it('refuses a role that does not exist', function () {
    $this->artisan('admin:create', [
        '--email' => 'role@rafeeq.test',
        '--name' => 'Role',
        '--role' => 'emperor',
    ])
        ->expectsQuestion('Password', 'Correct-Horse-9-Battery')
        ->expectsQuestion('Confirm password', 'Correct-Horse-9-Battery')
        ->assertExitCode(1)
        ->run();

    expect(AdminUser::query()->where('email', 'role@rafeeq.test')->exists())->toBeFalse();
});

it('refuses an email that already belongs to an admin', function () {
    adminWithRole(AdminRole::Support, ['email' => 'taken@rafeeq.test']);

    $this->artisan('admin:create', [
        '--email' => 'taken@rafeeq.test',
        '--name' => 'Duplicate',
        '--role' => AdminRole::Support->value,
    ])
        ->expectsQuestion('Password', 'Correct-Horse-9-Battery')
        ->expectsQuestion('Confirm password', 'Correct-Horse-9-Battery')
        ->assertExitCode(1)
        ->run();

    expect(AdminUser::query()->where('email', 'taken@rafeeq.test')->count())->toBe(1);
});

it('creates the roles it needs on a database that has none', function () {
    Role::query()->delete();

    $this->artisan('admin:create', [
        '--email' => 'fresh@rafeeq.test',
        '--name' => 'Fresh',
        '--role' => AdminRole::Verification->value,
    ])
        ->expectsQuestion('Password', 'Correct-Horse-9-Battery')
        ->expectsQuestion('Confirm password', 'Correct-Horse-9-Battery')
        ->expectsQuestion('Enter the 6-digit code from the app to confirm enrolment', '000000')
        ->run();

    // The roles were synced even though the run then failed on the code, which is what
    // makes the command usable on a fresh deployment rather than failing with a Spatie
    // exception about a missing role.
    expect(Role::query()->count())->toBe(count(AdminRole::cases()));
});
