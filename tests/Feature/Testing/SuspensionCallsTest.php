<?php

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Notification;

beforeEach(function () {
    config(['rafeeq.testing.api' => true]);
    AdminUser::factory()->create();

    $this->token = verifiedPassenger('01112223344');
});

function asSuspensionTester()
{
    return test()->flushHeaders()->withToken(test()->token);
}

function suspendForTest(): void
{
    asSuspensionTester()->postJson('/api/v1/testing/driver/suspend', ['scope' => 'account'])
        ->assertOk()->assertJsonPath('data.accountStatus', 'SUSPENDED');
}

function appealForTest(?string $evidence = null)
{
    return asSuspensionTester()->postJson('/api/v1/account/suspension/appeal', array_filter([
        'message' => 'I never did what the report says. Please look again.',
        'evidence' => $evidence,
    ]));
}

it('turns an appeal down and opens the second window with new evidence required', function () {
    suspendForTest();
    appealForTest()->assertSuccessful();

    asSuspensionTester()->postJson('/api/v1/testing/account/suspension/appeal/decide', ['decision' => 'upheld'])
        ->assertOk()
        ->assertJsonPath('data.accountStatus', 'SUSPENDED')
        ->assertJsonPath('data.suspension.appeal.canAppeal', true)
        ->assertJsonPath('data.suspension.appeal.needsNewEvidence', true)
        ->assertJsonPath('data.suspension.appeal.appeals.0.status', 'upheld');

    appealForTest('Trip receipt and a message from the driver.')->assertSuccessful();

    asSuspensionTester()->postJson('/api/v1/testing/account/suspension/appeal/decide', ['decision' => 'upheld'])->assertOk();

    appealForTest('More evidence.')
        ->assertStatus(422)
        ->assertJsonPath('error.fields.appeal.0', 'ALREADY_APPEALED');
});

it('accepts an appeal, lifts the hold and tells her', function () {
    suspendForTest();
    appealForTest()->assertSuccessful();

    asSuspensionTester()->postJson('/api/v1/testing/account/suspension/appeal/decide', ['decision' => 'overturned', 'note' => 'Report was mistaken.'])
        ->assertOk()
        ->assertJsonPath('data.accountStatus', 'ACTIVE')
        ->assertJsonPath('data.suspension', null);

    expect(Notification::query()->where('type', 'account_reinstated')->exists())->toBeTrue();
});

it('refuses to decide when no appeal is pending', function () {
    suspendForTest();

    asSuspensionTester()->postJson('/api/v1/testing/account/suspension/appeal/decide', ['decision' => 'upheld'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'SUSPENSION_APPEAL_NOT_OPEN');

    asSuspensionTester()->postJson('/api/v1/testing/account/suspension/appeal/decide', ['decision' => 'maybe'])
        ->assertStatus(422);
});

it('ages the hold so the appeal window closes', function () {
    suspendForTest();

    asSuspensionTester()->postJson('/api/v1/testing/account/suspension/age', ['hours' => 49])
        ->assertOk()
        ->assertJsonPath('data.suspension.appeal.canAppeal', false)
        ->assertJsonPath('data.suspension.appeal.appealBy', null);

    appealForTest()
        ->assertStatus(422)
        ->assertJsonPath('error.fields.appeal.0', 'APPEAL_WINDOW_CLOSED');
});

it('ages the answer to the first appeal too, closing the second window', function () {
    suspendForTest();
    appealForTest()->assertSuccessful();
    asSuspensionTester()->postJson('/api/v1/testing/account/suspension/appeal/decide', ['decision' => 'upheld'])->assertOk();

    asSuspensionTester()->postJson('/api/v1/testing/account/suspension/age', ['hours' => 49])
        ->assertOk()
        ->assertJsonPath('data.suspension.appeal.canAppeal', false);

    appealForTest('New evidence.')
        ->assertStatus(422)
        ->assertJsonPath('error.fields.appeal.0', 'APPEAL_WINDOW_CLOSED');
});

it('refuses to age an account that is not suspended', function () {
    asSuspensionTester()->postJson('/api/v1/testing/account/suspension/age', ['hours' => 49])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'MEMBER_NOT_SUSPENDED');
});

it('bans the caller permanently, and reinstate lifts it', function () {
    asSuspensionTester()->postJson('/api/v1/testing/account/ban')
        ->assertOk()
        ->assertJsonPath('data.accountStatus', 'SUSPENDED')
        ->assertJsonPath('data.nextStep', 'RESTRICTED_ACCOUNT')
        ->assertJsonPath('data.suspension.permanent', true)
        ->assertJsonPath('data.suspension.appeal.canAppeal', false);

    asSuspensionTester()->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.suspension.permanent', true);

    appealForTest()
        ->assertStatus(422)
        ->assertJsonPath('error.fields.appeal.0', 'PERMANENT_BAN');

    asSuspensionTester()->postJson('/api/v1/incidents', [])->assertStatus(422);
    asSuspensionTester()->getJson('/api/v1/content/support_contact')->assertNotFound();
    asSuspensionTester()->getJson('/api/v1/my-bookings')->assertForbidden();

    asSuspensionTester()->postJson('/api/v1/testing/account/ban')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'MEMBER_NOT_ACTIVE');

    asSuspensionTester()->postJson('/api/v1/testing/driver/reinstate', ['scope' => 'account'])
        ->assertOk()->assertJsonPath('data.accountStatus', 'ACTIVE');

    expect(User::query()->sole()->account_status)->toBe(AccountStatus::Active);
});
