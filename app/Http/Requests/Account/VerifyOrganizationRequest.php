<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Work or university verification by email domain.
 *
 * ---
 *
 * Maintainer note: this endpoint grants the level on a domain match, so the
 * address must already be proven. It is `email_verified_at` that carries that
 * proof — see `VerifyOrganizationByEmailAction` for why a typed address is not
 * evidence of an employer on its own.
 */
final class VerifyOrganizationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // The organization to be verified against. Must already be one
            // Rafeeq has verified and holds an email domain for; everyone else
            // uses the badge-upload path instead.
            'organizationId' => ['required', 'string', 'size:26', 'exists:organizations,id'],

            // Your work or university address. Its domain has to match the
            // organization's.
            'email' => ['required', 'email:rfc', 'max:150'],
        ];
    }
}
