<?php

namespace App\Http\Requests\Auth;

use App\Domains\Identity\Enums\DevicePlatform;
use App\Domains\Identity\Enums\OtpPurpose;
use App\Domains\Shared\ValueObjects\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * Chapter 2 §23.1. Note what is NOT validated here: whether the number has
 * an account. This request cannot tell, and neither can its response.
 *
 * ---
 *
 * Maintainer notes, deliberately kept OUT of the rules array: Scramble
 * publishes the comment above each rule as that field's description in the
 * OpenAPI document, which the external Flutter team reads. Comments there
 * are written for them, so anything about our internal reasoning belongs
 * here instead.
 *
 * - `purpose` accepts only the two an anonymous caller may ever ask for.
 *   `phone_change` and `high_risk_action` are minted by their own
 *   authenticated endpoints, so no unauthenticated request can produce a
 *   code that authorises them (pitfall #31). `Rule::enum()->only()` is used
 *   rather than `Rule::in` on the raw values because it pins the type as
 *   well as the allowed set, and because adding a case to `OtpPurpose`
 *   cannot then silently widen what this endpoint accepts.
 * - `phone` is validated for format only; normalisation happens in
 *   `after()` so the Action never sees the difference between the ways an
 *   Egyptian number can be typed (pitfall #62).
 */
final class RequestOtpRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Any Egyptian mobile format: local (01xxxxxxxxx), E.164
            // (+201xxxxxxxxx), or with the 0020 prefix. Arabic-Indic digits
            // are accepted. Normalised server-side, so send whatever the
            // person typed.
            'phone' => ['required', 'string', 'max:30'],

            // Why the code is being sent. Defaults to `authentication`, which
            // covers both first-time registration and signing in again. Use
            // `pin_reset` for the "Forgot PIN?" flow.
            'purpose' => ['sometimes', 'string', Rule::enum(OtpPurpose::class)
                ->only([OtpPurpose::Authentication, OtpPurpose::PinReset])],

            'device' => ['required', 'array'],

            // An identifier the app generates on first launch and keeps in
            // local storage. Never an advertising id or a hardware serial: a
            // reinstall is meant to produce a NEW value, which is what makes
            // the app ask for a PIN again on a fresh installation.
            'device.publicId' => ['required', 'string', 'max:64'],
            'device.platform' => ['required', Rule::enum(DevicePlatform::class)],
            'device.model' => ['nullable', 'string', 'max:80'],
            'device.osVersion' => ['nullable', 'string', 'max:30'],
            'device.appVersion' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * Normalisation is a validation concern, not the Action's: `01012345678`,
     * `+20 10 1234 5678` and `٠١٠١٢٣٤٥٦٧٨` are the same person, and the
     * Action should never see the difference (pitfall #62).
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('phone')) {
                    return;
                }

                try {
                    PhoneNumber::fromRaw((string) $this->input('phone'));
                } catch (InvalidArgumentException) {
                    $validator->errors()->add('phone', __('errors.AUTH_PHONE_INVALID'));
                }
            },
        ];
    }

    public function phone(): PhoneNumber
    {
        return PhoneNumber::fromRaw((string) $this->input('phone'));
    }

    public function purpose(): OtpPurpose
    {
        return OtpPurpose::from((string) $this->input('purpose', OtpPurpose::Authentication->value));
    }
}
