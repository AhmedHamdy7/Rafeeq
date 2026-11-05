<?php

namespace App\Http\Requests\Trip;

use Illuminate\Foundation\Http\FormRequest;

final class ListDriverTripsRequest extends FormRequest
{
    public const string UPCOMING = 'upcoming';

    public const string PAST = 'past';

    public function rules(): array
    {
        return [
            'scope' => ['required', 'string', 'in:'.self::UPCOMING.','.self::PAST],
            'commuteId' => ['nullable', 'string', 'size:26'],
        ];
    }
}
