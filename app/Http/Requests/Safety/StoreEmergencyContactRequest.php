<?php

namespace App\Http\Requests\Safety;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding or changing a trusted contact.
 *
 * ---
 *
 * Maintainer note: there is no `verifiedAt` field and there never should be. Whether a number
 * actually receives messages is something only an OTP to that number can establish (Phase 12); a
 * client-settable flag would let anybody mark a contact "confirmed" without ever reaching it, and
 * that badge is precisely what would stop somebody looking twice at a contact they did not add.
 */
final class StoreEmergencyContactRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            // What to call them. Shown only to the person who added them.
            'name' => [$required, 'string', 'max:150'],

            // Any Egyptian format; Arabic-Indic digits are normalised like everywhere else.
            'phone' => [$required, 'string', 'max:30'],

            // "Sister", "husband", "flatmate" — free text, for the person's own recall.
            'relationship' => ['nullable', 'string', 'max:50'],

            /*
             * 🔒 Whether this contact sees EVERY trip automatically. The single most consequential
             * boolean in the request: turned on, it gives somebody a standing feed of where the
             * owner goes each morning.
             */
            'autoShareTrips' => ['sometimes', 'boolean'],

            // Elevated access during an emergency.
            'isGuardian' => ['sometimes', 'boolean'],
        ];
    }
}
