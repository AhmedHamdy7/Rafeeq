<?php

namespace App\Http\Resources;

use App\Domains\Identity\Actions\RecordConsentAction;
use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Enums\CapabilityStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserCapability;
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
            /*
             * Why the account is on hold, in the caller's language — a category the member can
             * be shown, never the note staff wrote to the file. Present only while suspended.
             */
            'suspensionReason' => $this->when(
                $this->suspension_reason !== null,
                fn () => __('auth.suspension_reasons.'.$this->suspension_reason),
            ),
            /*
             * The two things screen 35 shows next to the reason: a reference to quote to the
             * safety team, and when the platform promised to look again. Present only while
             * suspended.
             */
            'suspension' => $this->when(
                $this->account_status === AccountStatus::Suspended,
                fn () => SuspensionSummary::for($this->resource),
            ),
            'profileStatus' => strtoupper($this->profile_status->value),
            /*
             * What this account may actually do: `["PASSENGER"]`, `["DRIVER"]` or both. Empty
             * until the role step is done.
             *
             * 🔴 Read THIS, not `registeredRole`, to decide which surfaces to show. The two can
             * legitimately differ: somebody who joined as a passenger and later added driving
             * keeps `registeredRole: passenger` — that field records what she originally came
             * for, and only this list says what is open to her now.
             */
            'capabilities' => $this->capabilityValues(),
            /*
             * Whether the role step has been completed. The app should not infer this from
             * `capabilities` being empty, because the two are set together and this is the one
             * `nextStep` is derived from.
             */
            'initialRoleSelected' => $this->initial_role_selected_at !== null,
            'registeredRole' => $this->registered_role?->value,
            'orgType' => $this->org_type?->value,
            'organizationId' => $this->organization_id,
            'preferredLanguage' => $this->preferred_language,
            'trustLevel' => $this->trust_level,
            // Drives the forced re-acceptance prompt when new terms ship.
            'outstandingConsents' => RecordConsentAction::outstandingFor($this->resource),
        ];
    }

    /**
     * The active capabilities, upper-cased like every other enum in the API.
     *
     * Its own method with a declared return type so the OpenAPI document gets `string[]` rather
     * than an untyped array — a `RatingResource` field published as `mixed` once already, and the
     * mobile team cannot generate a client from `mixed`.
     *
     * @return array<int, string>
     */
    private function capabilityValues(): array
    {
        /*
         * `loadMissing`, not a bare `$this->capabilities`. Strict mode turns an implicit lazy load
         * into an exception, and this Resource is built from a User that callers hand over without
         * eager-loading — `auth/me`, the profile submission and the sign-in response all do. An
         * explicit load is also a no-op when the relation is already there, so the one caller that
         * does eager-load pays nothing.
         */
        $this->resource->loadMissing('capabilities');

        return $this->capabilities
            ->where('status', CapabilityStatus::Active)
            /*
             * 🔴 Sorted, and this is a fix rather than tidiness.
             *
             * The relation has no `orderBy`, so MySQL returned the rows in whatever order suited
             * it — which is stable enough to look deliberate in development and is not stable at
             * all. CI caught it: the same request answered `[PASSENGER, DRIVER]` locally and
             * `[DRIVER, PASSENGER]` under a parallel run, on a list the API publishes as an
             * ordered one.
             *
             * By id, which for ULIDs is creation order, and creation order carries meaning here:
             * somebody who registered as Both gets Passenger first because
             * `Capability::forRegisteredRole()` enrols her that way, and Feature 01 v3 is explicit
             * that "Both starts Passenger". Sorting alphabetically would answer DRIVER first and
             * quietly contradict the rule the home screen follows.
             */
            ->sortBy(fn (UserCapability $row): string => (string) $row->getKey())
            ->map(fn (UserCapability $row): string => strtoupper($row->capability->value))
            ->values()
            ->all();
    }
}
