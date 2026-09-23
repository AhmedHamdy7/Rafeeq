<?php

namespace App\Http\Requests\Auth;

use App\Domains\Identity\Enums\DevicePlatform;
use App\Domains\Identity\Support\AuthSettings;
use App\Domains\Identity\Support\DeviceIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Chapter 2 §23.2.
 *
 * ---
 *
 * Maintainer note: comments above the rules become the field descriptions in
 * the OpenAPI document that the external Flutter team reads, so they are
 * written for that audience. `code` is validated as digits of the exact
 * configured length deliberately — a malformed value is rejected here and so
 * never consumes one of the challenge's attempts.
 */
final class VerifyOtpRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // The `challengeId` returned by POST /v1/auth/otp/request.
            'challengeId' => ['required', 'string', 'size:26'],

            // The code from the SMS. Digits only, exactly this many —
            // anything else is rejected as a malformed request rather than
            // counted as a wrong code.
            'code' => ['required', 'string', 'digits:'.AuthSettings::otpLength()],

            'device' => ['required', 'array'],

            // Must be the same value sent when the code was requested.
            'device.publicId' => ['required', 'string', 'max:64'],
            'device.platform' => ['required', Rule::enum(DevicePlatform::class)],
            'device.model' => ['nullable', 'string', 'max:80'],
            'device.osVersion' => ['nullable', 'string', 'max:30'],
            'device.appVersion' => ['nullable', 'string', 'max:20'],
            'device.pushToken' => ['nullable', 'string', 'max:512'],
        ];
    }

    public function deviceIdentity(): DeviceIdentity
    {
        return DeviceIdentity::fromArray($this->validated('device'));
    }
}
