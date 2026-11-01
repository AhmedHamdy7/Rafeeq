<?php

namespace App\Domains\Booking\Enums;

use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;

enum PaymentType: string
{
    case Cash = 'cash';

    /**
     * Kept in the enum for when a provider exists, and refused until then (P-4, PAY-01): the beta
     * is cash only. See `rafeeq.booking.online_payments_enabled`.
     */
    case Online = 'online';

    public function isAvailable(): bool
    {
        return match ($this) {
            self::Cash => true,
            self::Online => (bool) config('rafeeq.booking.online_payments_enabled'),
        };
    }

    /**
     * Refuses a payment type the platform cannot collect today, with `PAYMENT_TYPE_NOT_AVAILABLE`
     * rather than a validation error: the value is a real one, it is just not on offer yet.
     */
    public function assertAvailable(): void
    {
        if (! $this->isAvailable()) {
            throw DomainException::of(ErrorCode::PaymentTypeNotAvailable, fields: [
                'paymentType' => [__('errors.PAYMENT_TYPE_NOT_AVAILABLE')],
            ]);
        }
    }
}
