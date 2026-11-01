<?php

namespace App\Http\Requests\Group;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Telling the group you will be away for a stretch of days.
 *
 * ---
 *
 * Maintainer notes:
 *
 * - `releasesSeat` defaults to FALSE, and that default is the conservative one on
 *   purpose: releasing a seat gives it away, possibly to somebody who takes it, and
 *   cancelling the absence afterwards does not bring it back. A field that quietly
 *   defaulted to true would hand somebody's seat away because they did not send a
 *   key.
 * - The maximum length of an absence is a config number, checked in the Action.
 *   Beyond it, the honest operation is leaving the group rather than an absence that
 *   holds a seat nobody is using for a term.
 * - `after_or_equal` rather than `after`: a one-day absence has the same start and
 *   end date, and it is the commonest kind.
 */
final class PlanAbsenceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // The first day you will be away.
            'fromDate' => ['required', 'date_format:Y-m-d'],

            // The last day you will be away — the same date as `fromDate` for a single day, at
            // most a month after it. Leave it out for a pause with no end date (R-7).
            'toDate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fromDate'],

            // Why, if you want the group to know. Optional.
            'reason' => ['nullable', 'string', 'max:255'],

            // Ignored: a pause always gives your seat to the waiting list and puts you on that
            // list yourself, with no guaranteed return (R-7). Kept so older apps still work.
            'releasesSeat' => ['nullable', 'boolean'],
        ];
    }
}
