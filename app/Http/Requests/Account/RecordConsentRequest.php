<?php

namespace App\Http\Requests\Account;

use App\Domains\Identity\Enums\ConsentDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RecordConsentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'nullable', 'string', Rule::in([
                ConsentDocumentType::Terms->value,
                ConsentDocumentType::Privacy->value,
                ConsentDocumentType::Biometric->value,
            ])],
        ];
    }

    public function type(): ?ConsentDocumentType
    {
        return ConsentDocumentType::tryFrom((string) $this->input('type'));
    }
}
