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
             * A phone number or email in it is refused by the Action (`CHAT_CONTACT_INFO_NOT_ALLOWED`).
             */
            'body' => ['required', 'string', 'min:1', 'max:500'],
        ];
    }
}
