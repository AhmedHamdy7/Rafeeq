<?php

use App\Domains\Identity\Enums\OrgType;
use App\Domains\Identity\Models\Organization;
use App\Domains\Identity\Models\User;
use App\Domains\Verification\Enums\VerificationMethod;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\UserVerification;

/**
 * Level 4 without a reviewer, for a work or university email domain — the
 * cheap path for the population the product is aimed at, people commuting to a
 * known workplace or campus.
 */
beforeEach(function () {
    $this->token = signIn()['session']['accessToken'];
    completeBasicProfile($this->token);

    $this->org = Organization::factory()->create([
        'name' => 'Smart Village',
        'type' => 'work',
        'email_domain' => 'smartvillage.eg',
        'is_verified' => true,
    ]);
});

function verifyOrganization(string $token, string $organizationId, string $email)
{
    return test()->withToken($token)->postJson('/api/v1/account/verifications/organization', [
        'organizationId' => $organizationId,
        'email' => $email,
    ]);
}

it('grants the level immediately when the email domain matches', function () {
    $centre = verifyOrganization($this->token, $this->org->id, 'nour@smartvillage.eg')
        ->assertOk()
        ->json('data');

    $row = collect($centre['rows'])->firstWhere('type', 'organization');

    expect($row['status'])->toBe('APPROVED')
        ->and($row['method'])->toBe('email_domain')
        ->and($centre['level'])->toBe(2)
        ->and(User::sole()->trust_level)->toBe(2);
});

it('attaches the organization to the account and trusts the address it proved', function () {
    verifyOrganization($this->token, $this->org->id, 'nour@smartvillage.eg')->assertOk();

    $user = User::sole();

    expect($user->organization_id)->toBe($this->org->id)
        ->and($user->org_type)->toBe(OrgType::Work)
        ->and($user->email)->toBe('nour@smartvillage.eg')
        // Proven by the same act that proved the organization — recording it as
        // unverified would leave the account unable to use an address we
        // already trust.
        ->and($user->email_verified_at)->not->toBeNull();
});

it('records the method as email_domain, not as a badge review', function () {
    verifyOrganization($this->token, $this->org->id, 'nour@smartvillage.eg')->assertOk();

    expect(UserVerification::where('type', VerificationType::Organization->value)->sole()->method)
        ->toBe(VerificationMethod::EmailDomain);
});

it('refuses an address from a different domain', function () {
    verifyOrganization($this->token, $this->org->id, 'nour@gmail.com')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'ORGANIZATION_EMAIL_MISMATCH');

    expect(User::sole()->organization_id)->toBeNull()
        ->and(User::sole()->trust_level)->toBe(1);
});

it('matches the domain case-insensitively', function () {
    verifyOrganization($this->token, $this->org->id, 'Nour@SmartVillage.EG')->assertOk();
});

/**
 * Otherwise anyone could register an organization with a domain they own and
 * mint themselves a badge for it.
 */
it('refuses an organization Rafeeq has not verified', function () {
    $unverified = Organization::factory()->create([
        'email_domain' => 'startup.example',
        'is_verified' => false,
    ]);

    verifyOrganization($this->token, $unverified->id, 'nour@startup.example')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'ORGANIZATION_EMAIL_MISMATCH');
});

it('refuses an organization with no domain on file', function () {
    $noDomain = Organization::factory()->create([
        'email_domain' => null,
        'is_verified' => true,
    ]);

    verifyOrganization($this->token, $noDomain->id, 'nour@anything.eg')->assertStatus(422);
});

it('refuses to grant the same level twice', function () {
    verifyOrganization($this->token, $this->org->id, 'nour@smartvillage.eg')->assertOk();

    verifyOrganization($this->token, $this->org->id, 'nour@smartvillage.eg')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'VERIFICATION_ALREADY_APPROVED');
});

it('rejects an unknown organization and a malformed address', function () {
    verifyOrganization($this->token, str_repeat('0', 26), 'nour@smartvillage.eg')->assertStatus(422);
    verifyOrganization($this->token, $this->org->id, 'not-an-email')->assertStatus(422);
});

/**
 * A school is a valid organization type in the ERD, so a member of one has to
 * be expressible on an account — see the note on `OrgType`.
 */
it('can record a school as the account org type', function () {
    $school = Organization::factory()->create([
        'type' => 'school',
        'email_domain' => 'school.eg',
        'is_verified' => true,
    ]);

    verifyOrganization($this->token, $school->id, 'nour@school.eg')->assertOk();

    expect(User::sole()->org_type)->toBe(OrgType::School);
});
