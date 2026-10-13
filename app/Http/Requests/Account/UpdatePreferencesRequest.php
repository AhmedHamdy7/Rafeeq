<?php

namespace App\Http\Requests\Account;

use App\Domains\Identity\Enums\Capability;
use App\Domains\Identity\Enums\ThemePreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UI state the person wants kept across devices.
 *
 * ---
 *
 * Maintainer notes (comments above the rules are published as field descriptions in the OpenAPI
 * document, so they are written for the mobile team — binding standard #38):
 *
 * 🔒 Nothing here grants anything. `activeMode` chooses a home screen; what the person may do is
 * their capabilities, checked on every protected request. v3 is explicit that the server validates
 * capability and restriction per request rather than trusting client flags — so a client that sets
 * `activeMode: driver` without the driver capability gets the preference stored and every driver
 * action still refused.
 *
 * That is also why this endpoint cannot enrol anything: v3 asks for preferences to be updatable
 * "without granting capabilities", and `POST /account/initial-role` is the only way to gain one.
 */
final class UpdatePreferencesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /*
             * Which home to open on this account's next launch: `passenger` or `driver`. A
             * preference, not a permission — it grants nothing on its own.
             */
            'activeMode' => ['sometimes', 'string', Rule::enum(Capability::class)],

            /*
             * Send `true` once the three intro slides have been seen, so they are not shown again
             * on a new device. There is no way to set it back to unseen.
             */
            'onboardingSeen' => ['sometimes', 'boolean'],

            /*
             * Send `true` once the driver walkthrough has been shown. Only meaningful for an
             * account with the driver capability, and likewise cannot be unset.
             */
            'driverWalkthroughSeen' => ['sometimes', 'boolean'],

            // `ar` or `en`. Used for notifications the server sends you; the language of a
            // response always follows this request's `Accept-Language` header instead.
            'language' => ['sometimes', 'string', 'in:ar,en'],

            // `light`, `dark` or `system`.
            'theme' => ['sometimes', 'string', Rule::enum(ThemePreference::class)],
        ];
    }
}
