<?php

namespace App\Http\Requests\Notification;

use App\Domains\Notification\Enums\NotificationCategory;
use App\Domains\Notification\Enums\NotificationChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Turning categories of notification on and off (Chapter 11 §Preferences).
 *
 * ---
 *
 * Maintainer notes: only the channels a member actually receives are accepted (push and
 * in-app). Safety is accepted in the payload and refused by the Action with its own code, so the
 * client can say why rather than show a generic validation error.
 */
final class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // One entry per switch that changed. Switches not sent keep their current value.
            'preferences' => ['required', 'array', 'min:1', 'max:20'],

            // `booking` · `payment` · `trip` · `safety` · `marketing`. Safety cannot be turned off.
            'preferences.*.category' => ['required', 'string', Rule::enum(NotificationCategory::class)],

            // `push` (the phone buzzes) or `in_app` (kept in the inbox).
            'preferences.*.channel' => ['required', 'string', Rule::enum(NotificationChannel::class)->only([
                NotificationChannel::Push,
                NotificationChannel::InApp,
            ])],

            'preferences.*.enabled' => ['required', 'boolean'],
        ];
    }
}
