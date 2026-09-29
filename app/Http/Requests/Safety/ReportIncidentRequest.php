<?php

namespace App\Http\Requests\Safety;

use App\Domains\Safety\Enums\IncidentCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filing a report (Chapter 10's incident wizard).
 *
 * ---
 *
 * Maintainer notes:
 *
 * - **There is no `severity` field.** It is derived from the category by `ReportIncidentAction`. A
 *   reporter cannot be asked to rate their own emergency, and a client that could set it would own
 *   the ordering of the safety queue.
 * - **There is no `reportedUserId`.** Who the report is about is derived from the booking. Accepting
 *   it would be a way to put a mark against a stranger.
 * - `bookingId` is optional: a report about somebody impersonating a Rafeeq driver has no booking,
 *   and that is exactly the report the platform most needs to receive.
 */
final class ReportIncidentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // What happened, as one of the categories the chapter lists.
            'category' => ['required', 'string', Rule::enum(IncidentCategory::class)],

            /*
             * In their own words. Optional because a category alone is a valid report — somebody
             * shaken may not want to type, and refusing them would lose the report entirely.
             */
            'description' => ['nullable', 'string', 'max:5000'],

            // The journey it was about, if there was one. Must be the reporter's own.
            'bookingId' => ['nullable', 'string', 'size:26'],
        ];
    }
}
