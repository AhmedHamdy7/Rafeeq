<?php

namespace App\Http\Requests\Booking;

use App\Domains\Booking\Enums\BookingStatus;
use Illuminate\Foundation\Http\FormRequest;

final class ListDriverBookingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'commuteId' => ['nullable', 'string', 'size:26'],
            'tripId' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', 'string', 'max:200', function (string $attribute, mixed $value, \Closure $fail): void {
                foreach (self::split($value) as $status) {
                    if (BookingStatus::tryFrom($status) === null) {
                        $fail(__('validation.in', ['attribute' => $attribute]));

                        return;
                    }
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
        return array_values(array_filter(array_map(
            fn (string $status): string => strtolower(trim($status)),
            explode(',', (string) $value),
        )));
    }
}
