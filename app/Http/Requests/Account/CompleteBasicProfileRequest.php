<?php

namespace App\Http\Requests\Account;

use App\Domains\Identity\Enums\Gender;
use App\Domains\Identity\Enums\OrgType;
use App\Domains\Identity\Enums\RegisteredRole;
use App\Domains\Identity\Support\ProfileSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Minimal profile setup (Chapter 2 §17.3).
 *
 * ---
 *
 * Maintainer notes, kept out of the rules array because Scramble publishes
 * the comment above each rule as that field's description in the OpenAPI
 * document the external Flutter team reads:
 *
 * - The two `regex` rules split one job in two: the first restricts the
 *   character set, the second demands at least one letter. Together they
 *   reject a digits-only "name" without banning a digit inside a name that
 *   legitimately contains one.
 * - `after:` on `dateOfBirth` is a plausibility bound, not policy — it
 *   catches a typo like 1089 that would otherwise sail through.
 * - `'string'` next to `Rule::in` is not redundant: without it the field has
 *   no declared type, and the contract would list the allowed values for an
 *   untyped field.
 * - Idempotent by design (scenario J): a resubmission overwrites rather than
 *   conflicting, so setup survives an interrupted connection.
 */
final class CompleteBasicProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // The person's real name, in Arabic or Latin script. Spaces,
            // hyphens, apostrophes and full stops are allowed; it must contain
            // at least one letter. If they later become a driver, this should
            // match their identity documents.
            'fullName' => [
                'required', 'string',
                'min:'.ProfileSettings::fullNameMinLength(),
                'max:'.ProfileSettings::fullNameMaxLength(),
                'regex:/^[\p{Arabic}\p{Latin}\s\'\-\.]+$/u',
                'regex:/\p{L}/u',
            ],

            // What other members see. Derived from the first word of
            // `fullName` when omitted — the full name is never shown to other
            // members.
            'publicFirstName' => ['nullable', 'string', 'max:50'],

            // Used for commute preferences and community safety settings, and
            // never returned by any endpoint, including this one.
            'gender' => ['required', Rule::enum(Gender::class)],

            // Which capability the person came for. Both are always available
            // on the account; this only shapes onboarding.
            'registeredRole' => ['required', Rule::enum(RegisteredRole::class)],

            // ISO date. Must put the person at or above the minimum supported
            // age, which is a platform setting and may change.
            'dateOfBirth' => [
                'nullable', 'date', 'date_format:Y-m-d',
                'before_or_equal:'.now()->subYears(ProfileSettings::minimumAgeYears())->toDateString(),
                'after:'.now()->subYears(120)->toDateString(),
            ],

            // Language for notifications, and the fallback for API messages
            // when a request arrives without `Accept-Language`.
            'preferredLanguage' => ['nullable', 'string', Rule::in(['ar', 'en'])],

            'orgType' => ['nullable', Rule::enum(OrgType::class)],
            'organizationId' => ['nullable', 'string', 'size:26', 'exists:organizations,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'dateOfBirth.before_or_equal' => __('validation.custom.date_of_birth.minimum_age', [
                'age' => ProfileSettings::minimumAgeYears(),
            ]),
            'fullName.regex' => __('validation.custom.full_name.format'),
        ];
    }
}
