<?php

namespace App\Http\Resources;

use App\Domains\Identity\Actions\RecordConsentAction;
use App\Domains\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in person's view of THEIR OWN account.
 *
 * Every field is listed explicitly — never `$this->resource->toArray()` —
 * because the model's `#[Hidden]` list is a safety net, not the contract.
 * `gender` is absent on purpose and stays absent: it is a hard matching
 * filter (Bible §15.2, pitfall #30) and appears in no response at all, not
 * even this one. Other members see {@see MemberResource}, never this.
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Safe here and nowhere else: this is the person's own number,
            // shown so they can confirm which account they are in.
            'phone' => $this->phone_e164,
            'phoneVerified' => $this->phone_verified_at !== null,
            'fullName' => $this->full_name,
            'publicFirstName' => $this->public_first_name,
            'profilePhotoPath' => $this->profile_photo_path,
            'dateOfBirth' => $this->date_of_birth?->toDateString(),
            'email' => $this->email,
            'accountStatus' => strtoupper($this->account_status->value),
            'suspensionReason' => $this->when(
                $this->suspension_reason !== null,
                $this->suspension_reason,
            ),
            'profileStatus' => strtoupper($this->profile_status->value),
            'registeredRole' => $this->registered_role?->value,
            'orgType' => $this->org_type?->value,
            'organizationId' => $this->organization_id,
            'preferredLanguage' => $this->preferred_language,
            'trustLevel' => $this->trust_level,
            // Drives the forced re-acceptance prompt when new terms ship.
            'outstandingConsents' => RecordConsentAction::outstandingFor($this->resource),
        ];
    }
}
