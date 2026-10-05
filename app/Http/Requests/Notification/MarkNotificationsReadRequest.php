<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Marking inbox items read (Chapter 11: `PATCH /notifications/read`).
 *
 * ---
 *
 * Maintainer notes: either a list of ids or `all`, and ids that are not the caller's are
 * ignored rather than refused — answering "that id is not yours" would confirm it exists.
 */
final class MarkNotificationsReadRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // The notifications to mark read. Ignored when `all` is true.
            'ids' => ['required_without:all', 'array', 'max:100'],
            'ids.*' => ['string', 'max:26'],

            // Mark everything in the inbox read ("Mark all as read").
            'all' => ['nullable', 'boolean'],
        ];
    }
}
