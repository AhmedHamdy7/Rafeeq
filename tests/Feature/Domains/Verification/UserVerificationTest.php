<?php

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Identity\Models\User;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\IdentityDocument;
use App\Domains\Verification\Models\UserVerification;
use Illuminate\Database\QueryException;

it('rejects a duplicate verification type for the same user', function () {
    $user = User::factory()->create();

    UserVerification::factory()->for($user)->create(['type' => VerificationType::GovernmentId]);
    UserVerification::factory()->for($user)->create(['type' => VerificationType::GovernmentId]);
})->throws(QueryException::class);

it('allows the same user to have different verification types', function () {
    $user = User::factory()->create();

    UserVerification::factory()->for($user)->create(['type' => VerificationType::GovernmentId]);
    $selfie = UserVerification::factory()->for($user)->create(['type' => VerificationType::Selfie]);

    expect($selfie->exists)->toBeTrue();
});

it('reports approval state via the status enum', function () {
    $pending = UserVerification::factory()->create();
    $approved = UserVerification::factory()->approved()->create();

    expect($pending->isApproved())->toBeFalse()
        ->and($approved->isApproved())->toBeTrue()
        ->and($approved->status)->toBe(VerificationStatus::Approved);
});

it('links a rejection to an admin reviewer with an actionable reason', function () {
    $admin = AdminUser::factory()->create();
    $verification = UserVerification::factory()->rejected()->create(['reviewed_by' => $admin->id]);

    expect($verification->reviewer->id)->toBe($admin->id)
        ->and($verification->rejection_reason)->not->toBeEmpty();
});

it('loads its identity documents', function () {
    $verification = UserVerification::factory()
        ->has(IdentityDocument::factory()->count(2), 'documents')
        ->create();

    expect($verification->documents)->toHaveCount(2);
});
