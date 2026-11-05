<?php

namespace App\Http\Requests\Commute;

use App\Domains\Commute\Enums\CommuteOfferStatus;
use Illuminate\Foundation\Http\FormRequest;

final class ListCommutesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'max:100', function (string $attribute, mixed $value, \Closure $fail): void {
                foreach (self::split($value) as $status) {
                    if (CommuteOfferStatus::tryFrom($status) === null) {
                        $fail(__('validation.in', ['attribute' => $attribute]));

                        return;
                    }
                }
            }],
        ];
    }

    /**
     * @return list<string>
     */
    public function statuses(): array
    {
        return self::split($this->validated('status'));
    }

    /**
     * @return list<string>
     */
    private static function split(mixed $value): array
    {
        return array_values(array_filter(array_map(
            fn (string $status): string => strtolower(trim($status)),
            explode(',', (string) $value),
        )));
    }
}
