<?php

namespace App\Http\Requests\Safety;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Appealing the decision on one of your reports (HISTORY-10).
 *
 * ---
 *
 * Maintainer notes:
 *
 * Only a reason. Whether this is the first appeal or the second, and whether there is new evidence,
 * is the server's to work out from the report — a `round` or `hasNewEvidence` field would let a
 * client claim the second appeal's condition instead of meeting it.
 */
final class AppealIncidentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /*
             * Why the decision is wrong, in their own words (10–1000 characters). For a second
             * appeal, attach the new evidence first (`POST /incidents/{incident}/evidence`): the
             * appeal is refused with `NEW_EVIDENCE_REQUIRED` unless more files are attached than when
             * the first appeal was made.
             */
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
