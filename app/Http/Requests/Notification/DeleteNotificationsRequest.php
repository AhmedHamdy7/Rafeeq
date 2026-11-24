<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Removing inbox items (`DELETE /notifications`): a list of ids or `all`. Ids that are not the
 * caller's are ignored rather than refused.
 */
final class DeleteNotificationsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // The notifications to remove. Ignored when `all` is true.
            'ids' => ['required_without:all', 'array', 'max:100'],
            'ids.*' => ['string', 'max:26'],

            // Remove everything in the inbox.
            'all' => ['nullable', 'boolean'],
        ];
    }
}
