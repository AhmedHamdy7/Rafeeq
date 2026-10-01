<?php

namespace App\Http\Requests\Safety;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating a live-share link.
 *
 * ---
 *
 * Maintainer notes:
 *
 * - **No `expiresAt`.** How long a link lives is policy, not the client's: a share that outlived
 *   its journey would be a standing window onto wherever that person goes next, and letting the
 *   app choose would make that one API call away.
 * - `contactId` is optional. A share with no contact named is a link the person sends themselves,
 *   which is how it will usually be used — pasted into a chat with whoever they wanted to tell.
 */
final class ShareLiveTripRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // One of your own emergency contacts, if you want the share recorded against them.
            'contactId' => ['nullable', 'string', 'size:26'],
        ];
    }
}
