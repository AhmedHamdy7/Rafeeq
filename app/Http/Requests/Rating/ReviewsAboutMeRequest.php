<?php

namespace App\Http\Requests\Rating;

use App\Domains\Rating\Enums\RatingKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReviewsAboutMeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['sometimes', 'string', Rule::enum(RatingKind::class)],
        ];
    }

    public function kind(): ?RatingKind
    {
        return RatingKind::tryFrom((string) $this->query('kind'));
    }
}
