<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The app telling the server what it did locally.
 *
 * ---
 *
 * Maintainer notes (comments above the rules are published as field descriptions in the OpenAPI
 * document, so they are written for the mobile team — binding standard #38):
 *
 * 🔴 **`hasLocalPin` is gone.** The local PIN left the MVP (Feature 01 v3 §10) — the app does not
 * ask for one, nothing on the server routes on it, and the column is dropped. A client still
 * sending the field is ignored rather than refused, so taking it out of the app does not have to
 * happen on the same day as this deploy.
 *
 * Note what remains absent: any biometric template, and any value or hash of anything secret. The
 * server records a preference and a push token, which is useless to somebody who steals the
 * database.
 */
final class UpdateDeviceSecurityRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /*
             * Whether the person turned on biometric unlock for this installation. A PREFERENCE
             * only — it is never required and the server makes no decision from it.
             */
            'biometricEnabled' => ['sometimes', 'boolean'],

            // The push token for this installation. Null clears it.
            'pushToken' => ['sometimes', 'nullable', 'string', 'max:512'],
        ];
    }
}
