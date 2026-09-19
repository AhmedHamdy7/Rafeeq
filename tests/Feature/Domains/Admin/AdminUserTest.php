<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminUser;
use Spatie\Permission\Models\Role;

it('hides password_hash and mfa_secret from serialization', function () {
    $admin = AdminUser::factory()->create();

    expect($admin->toArray())->not->toHaveKey('password_hash')
        ->and($admin->toArray())->not->toHaveKey('mfa_secret');
});

it('authenticates using password_hash, not the default password column', function () {
    $admin = AdminUser::factory()->create();

    expect($admin->getAuthPassword())->toBe($admin->password_hash);
});

it('assigns and checks Spatie roles correctly with a ULID-keyed model', function () {
    Role::create(['name' => AdminRole::Verification->value, 'guard_name' => 'admin']);
    Role::create(['name' => AdminRole::Finance->value, 'guard_name' => 'admin']);

    $admin = AdminUser::factory()->create();

    $admin->assignRole(AdminRole::Verification->value);

    expect($admin->hasRole(AdminRole::Verification->value))->toBeTrue()
        ->and($admin->hasRole(AdminRole::Finance->value))->toBeFalse();
});

it('can log in and out through the session-driver admin guard', function () {
    // SessionGuard::logout() reads getRememberToken() unconditionally, which
    // throws MissingAttributeException under Model::shouldBeStrict() if the
    // remember_token column is absent.
    $admin = AdminUser::factory()->create();

    auth('admin')->login($admin);
    expect(auth('admin')->id())->toBe($admin->id);

    auth('admin')->logout();
    expect(auth('admin')->check())->toBeFalse();
});

it('reports MFA confirmation state', function () {
    $confirmed = AdminUser::factory()->create();
    $unconfirmed = AdminUser::factory()->create(['mfa_confirmed_at' => null]);

    expect($confirmed->hasMfaConfirmed())->toBeTrue()
        ->and($unconfirmed->hasMfaConfirmed())->toBeFalse();
});
