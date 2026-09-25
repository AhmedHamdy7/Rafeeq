<?php

namespace App\Http\Requests\Group;

use App\Domains\Group\Enums\GroupAttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "I'm coming tomorrow" / "I'm away tomorrow".
 *
 * ---
 *
 * Maintainer notes:
 *
 * - `no_response` is accepted as an input value because the enum has it, but it is
 *   what the ABSENCE of a declaration means; a client should not normally send it.
 *   It is left in rather than filtered out so that "I take that back" has a
 *   representation.
 * - The cutoff (how close to departure a declaration may still change) lives in
 *   config, not here: it is a policy number the dashboard will own, and a validation
 *   rule cannot see the trip's departure time anyway.
 */
final class DeclareAttendanceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // The day you are answering for.
            'tripId' => ['required', 'string', 'size:26'],

            // Whether you are travelling that day.
            'status' => ['required', 'string', Rule::enum(GroupAttendanceStatus::class)],
        ];
    }
}
