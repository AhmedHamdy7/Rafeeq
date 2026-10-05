<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

final class SendChatMessageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /*
             * The message. Short on purpose — pickup details and delays, not a correspondence.
             * A phone number or email in it is allowed but flagged (`containsContactInfo`), and the
             * recipient's app should warn before they act on it.
             */
            'body' => ['required', 'string', 'min:1', 'max:500'],
        ];
    }
}
