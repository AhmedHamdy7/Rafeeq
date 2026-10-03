<?php

namespace App\Http\Requests\Rating;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reporting a review as abusive.
 *
 * ---
 *
 * Maintainer notes (comments above the rules are published as field descriptions in the OpenAPI
 * document, so they are written for the mobile team — binding standard #38):
 *
 * There is no `action` and no `remove` field. A report puts the review in front of a person and
 * does nothing else; see `ReportReviewAction` on why a report that could take a review down would
 * make "report everything under four stars" a way to launder a record.
 */
final class ReportReviewRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /*
             * Why, in the reporter's own words. Required, because a moderation queue with no
             * reason on the row is a queue a human has to guess at — and the person reporting is
             * the only one who knows what is wrong with it.
             */
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
