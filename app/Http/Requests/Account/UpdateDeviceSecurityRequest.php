<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The app telling the server what it did locally (Chapter 2 §15/§16).
 *
 * Note what is absent: any PIN value, any hash of one, any biometric
 * template. The PIN verifier lives in the Keystore/Keychain and the server
 * stores only the two booleans — enough to route the launch flow and to
 * show "PIN enabled" on the devices screen, and useless to anyone who
 * steals the database.
 */
final class UpdateDeviceSecurityRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'hasLocalPin' => ['sometimes', 'boolean'],
            'biometricEnabled' => ['sometimes', 'boolean'],
            'pushToken' => ['sometimes', 'nullable', 'string', 'max:512'],
        ];
    }
}
