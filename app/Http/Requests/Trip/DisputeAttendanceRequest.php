<?php

namespace App\Http\Requests\Trip;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "That's not right" — the passenger contesting a trip they were recorded on.
 *
 * ---
 *
 * Maintainer notes:
 *
 * - The reason is REQUIRED and is free text, not a category. A reviewer settles this by
 *   reading what happened, and "I couldn't get to the car" and "she never came" would be
 *   the same category and opposite cases. A minimum length, because a dispute that says
 *   "no" gives a reviewer nothing to decide on and wastes the window it was raised in.
 * - There is no field for what the passenger thinks the status should be. A dispute
 *   records that a record is contested; letting the passenger set the value would move
 *   the same unchecked power from one party to the other.
 */
final class DisputeAttendanceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // What was wrong, in your own words. Read by a person, not matched by code.
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ];
    }
}
