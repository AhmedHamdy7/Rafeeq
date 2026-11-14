<?php

namespace App\Http\Requests\Testing;

use App\Domains\Reliability\Enums\ReliabilityEventCause;
use App\Domains\Reliability\Enums\ReliabilityEventKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class WriteReliabilityEventsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('kind'))) {
            $this->merge(['kind' => strtolower($this->input('kind'))]);
        }
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::enum(ReliabilityEventKind::class)],
            'cause' => ['nullable', 'string', Rule::enum(ReliabilityEventCause::class)],
            'count' => ['nullable', 'integer', 'between:1,10'],
            'minutesAgo' => ['nullable', 'integer', 'between:0,144000'],
            'grouped' => ['nullable', 'boolean'],
            'bookingId' => ['nullable', 'string', 'max:26'],
            'withFee' => ['nullable', 'boolean'],
            'feePercent' => ['nullable', 'integer', 'between:1,100'],
        ];
    }
}
