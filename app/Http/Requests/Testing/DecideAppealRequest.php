<?php

namespace App\Http\Requests\Testing;

use App\Domains\Reliability\Enums\AppealDeclineReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DecideAppealRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(['approved', 'declined'])],
            'declineReasonCode' => ['nullable', 'string', Rule::enum(AppealDeclineReason::class)],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
