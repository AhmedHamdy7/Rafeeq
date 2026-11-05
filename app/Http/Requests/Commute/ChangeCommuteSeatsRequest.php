<?php

namespace App\Http\Requests\Commute;

use App\Domains\Commute\Actions\ChangeCommuteSeatsAction;
use Illuminate\Foundation\Http\FormRequest;

final class ChangeCommuteSeatsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'seatsTotal' => ['required', 'integer', 'min:1', 'max:7'],
            'scope' => ['required', 'string', 'in:'.implode(',', ChangeCommuteSeatsAction::SCOPES)],
            'scheduledTripId' => ['required_unless:scope,'.ChangeCommuteSeatsAction::ALL_FUTURE_DAYS, 'nullable', 'string', 'size:26'],
        ];
    }
}
