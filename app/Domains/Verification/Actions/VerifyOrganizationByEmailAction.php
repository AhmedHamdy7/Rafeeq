<?php

namespace App\Domains\Verification\Actions;

use App\Domains\Identity\Models\Organization;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Verification\Enums\VerificationMethod;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\UserVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Level 4 without a reviewer, for anyone whose work or university email
 * domain matches a verified organization.
 *
 * This is the cheap path on purpose: it removes the two slowest levels of
 * friction — uploading a badge photo and waiting a day — for the population
 * the product is aimed at, people commuting to a known workplace or campus.
 *
 * It only grants the level once the email address itself has been proven, and
 * it does NOT prove it. Accepting a typed address as evidence would let anyone
 * claim any employer by typing an address there; the badge-review path exists
 * for everyone else.
 */
final readonly class VerifyOrganizationByEmailAction
{
    public function __construct(private RecomputeTrustLevelAction $recomputeTrustLevel) {}

    public function execute(User $user, Organization $organization, string $email): UserVerification
    {
        $domain = Str::lower(Str::after($email, '@'));

        // An organization with no domain on file cannot be proven this way —
        // and neither can an unverified one, or an attacker could register an
        // organization with a domain they own and mint badges for it.
        if (! $organization->is_verified
            || $organization->email_domain === null
            || Str::lower($organization->email_domain) !== $domain) {
            throw DomainException::of(ErrorCode::OrganizationEmailMismatch);
        }

        return DB::transaction(function () use ($user, $organization, $email): UserVerification {
            $verification = $user->verifications()->firstOrCreate(
                ['type' => VerificationType::Organization->value],
                ['method' => VerificationMethod::EmailDomain->value],
            );

            if ($verification->status === VerificationStatus::Approved) {
                throw DomainException::of(ErrorCode::VerificationAlreadyApproved);
            }

            $verification->status = VerificationStatus::Approved->value;
            $verification->method = VerificationMethod::EmailDomain->value;
            $verification->reviewed_at = now();
            $verification->rejection_reason = null;
            $verification->save();

            $user->organization_id = $organization->id;
            $user->org_type = $organization->type->toOrgType()->value;
            $user->email = $email;
            // Proven by the same act that proved the organization, so recording
            // it as unverified would leave the account unable to use an address
            // we already trust.
            $user->email_verified_at = now();
            $user->save();

            $this->recomputeTrustLevel->execute($user);

            return $verification;
        });
    }
}
