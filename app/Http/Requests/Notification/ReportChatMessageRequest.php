<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

final class ReportChatMessageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // What was wrong with the message, in the reporter's words. Goes to the safety team.
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
