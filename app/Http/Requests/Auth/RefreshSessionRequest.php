<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Chapter 2 §23.3. The refresh token travels in the body, not in an
 * Authorization header: this endpoint is reached precisely when the access
 * token has expired, and the two secrets must never be confused for each
 * other.
 */
final class RefreshSessionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'refreshToken' => ['required', 'string', 'min:32', 'max:128'],
        ];
    }
}
