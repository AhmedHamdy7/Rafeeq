<?php

namespace App\Http\Requests\Safety;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Blocking somebody.
 *
 * ---
 *
 * Maintainer note: the reason is for a support case and is **never shown to the blocked person**.
 * Nothing about a block reaches them at all — somebody blocking a person they are frightened of must
 * not thereby inform them of it.
 */
final class BlockUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Who to stop being matched with.
            'userId' => ['required', 'string', 'size:26'],

            // Optional, private to us.
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
