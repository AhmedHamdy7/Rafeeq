<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A suspended member appealing her hold (AUTH-19, follow-up F1-3).
 */
final class AppealSuspensionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Her side of it, in her words. Staff read it beside the case.
            'message' => ['required', 'string', 'min:10', 'max:1000'],

            /*
             * What she can show — a description, a reference, a link she was sent. Optional on the
             * first appeal; REQUIRED on the second, which exists only for evidence the first did
             * not have (`422`, `error.fields.appeal: ["EVIDENCE_REQUIRED"]`).
             */
            'evidence' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
