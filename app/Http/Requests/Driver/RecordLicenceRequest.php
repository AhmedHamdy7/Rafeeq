<?php

namespace App\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Licence and identity numbers for a driver application (Chapter 3 §6).
 *
 * ---
 *
 * Maintainer notes, kept out of the rules array because comments there are
 * published as field descriptions to the external mobile team:
 *
 * - Both numbers are stored encrypted with a separate sha256 column for
 *   duplicate detection, so neither is ever compared in the clear.
 * - The Egyptian national id is 14 digits and encodes the birth date and
 *   governorate; only the length and shape are checked here, with the real
 *   verification left to the reviewer looking at the document.
 * - The minimum remaining validity is a platform setting, not a literal —
 *   see `RecordLicenceDetailsAction` for why a licence expiring next week
 *   cannot be accepted.
 */
final class RecordLicenceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Your 14-digit national ID number, exactly as printed on the
            // card. Stored encrypted and never shown back to you.
            'nationalId' => ['required', 'string', 'digits:14'],

            // Your driving licence number, as printed on the licence.
            'licenceNumber' => ['required', 'string', 'min:4', 'max:30', 'regex:/^[A-Za-z0-9\-\/]+$/'],

            // The licence expiry date. It has to stay valid long enough for
            // the review itself, so a licence expiring within the next few
            // weeks is refused.
            'licenceExpiry' => ['required', 'date', 'date_format:Y-m-d', 'after:today'],
        ];
    }
}
