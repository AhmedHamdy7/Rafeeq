<?php

namespace App\Http\Requests\Account;

use App\Domains\Identity\Enums\RegisteredRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The last step of onboarding: Passenger, Driver or Both.
 *
 * ---
 *
 * Maintainer notes (comments above the rules are published as field descriptions in the OpenAPI
 * document, so they are written for the mobile team — binding standard #38):
 *
 * Choosing `driver` or `both` does NOT make the person an approved driver. It records what they
 * came for and opens the driver surface so they can apply; publishing a commute still requires an
 * approved driver profile and a verified vehicle (Feature 02). Treating this call as approval is
 * the one mistake worth guarding against here, so the field description says so.
 */
final class EnrollInitialRoleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /*
             * `passenger`, `driver` or `both`. Safe to send again — enrolling twice is the same as
             * enrolling once, so a retry after a dropped connection needs no special handling.
             *
             * `both` enrols both capabilities and starts on the Passenger home; the driver
             * walkthrough then runs the first time the driver side is opened.
             */
            'role' => ['required', 'string', Rule::enum(RegisteredRole::class)],
        ];
    }
}
