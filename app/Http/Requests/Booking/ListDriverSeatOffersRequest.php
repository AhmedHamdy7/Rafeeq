<?php

namespace App\Http\Requests\Booking;

use App\Domains\Booking\Support\DriverSeatOffers;
use Illuminate\Foundation\Http\FormRequest;

final class ListDriverSeatOffersRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'max:40', function (string $attribute, mixed $value, \Closure $fail): void {
                if (array_diff(self::split($value), [DriverSeatOffers::ROUND_OPEN, DriverSeatOffers::ROUND_ACCEPTED, DriverSeatOffers::ROUND_CLOSED]) !== []) {
                    $fail(__('validation.in', ['attribute' => $attribute]));
                }
            }],
        ];
    }

    public function statuses(): array
    {
        return self::split($this->validated('status'));
    }

    private static function split(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (string $part): string => strtoupper(trim($part)),
            explode(',', $value),
        ))));
    }
}
