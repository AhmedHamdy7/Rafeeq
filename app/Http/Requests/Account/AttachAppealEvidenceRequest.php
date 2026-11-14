<?php

namespace App\Http\Requests\Account;

use App\Domains\Safety\Enums\EvidenceKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AttachAppealEvidenceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(EvidenceKind::class)->only([
                EvidenceKind::Photo,
                EvidenceKind::Screenshot,
                EvidenceKind::Document,
            ])],

            'file' => [
                'required', 'file',
                'mimes:jpeg,jpg,png,webp',
                'max:'.config('rafeeq.safety.max_evidence_size_kilobytes'),
            ],
        ];
    }

    public function kind(): EvidenceKind
    {
        return EvidenceKind::from($this->validated('kind'));
    }
}
