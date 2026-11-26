<?php

namespace App\Http\Requests\Geo;

use Illuminate\Foundation\Http\FormRequest;

final class SuggestPlacesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'lat' => ['required_without:q', 'required_with:lng', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['required_without:q', 'required_with:lat', 'nullable', 'numeric', 'between:-180,180'],
            'kind' => ['nullable', 'string', 'in:start,end,pickup,dropoff'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('rafeeq.places.max_limit')],
        ];
    }
}
