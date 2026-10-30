<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Her appeal against one reliability event.
 *
 * ---
 *
 * Maintainer notes (comments above the rules are published as field descriptions in the OpenAPI
 * document, so they are written for the mobile team — binding standard #38):
 *
 * One appeal per event. Filing does **not** pause the event's effect on her score — a reviewer
 * decides, and she is told either way. An approved appeal stops the event counting while leaving it
 * readable, so she can see it was cleared.
 */
final class AppealReliabilityEventRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /*
             * What happened, in her words. **Required**, and it is the entire case: nothing is
             * attached to a reliability event for a reviewer to weigh, so her account of that
             * morning is all there is to read.
             */
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
