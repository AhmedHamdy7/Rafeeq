<?php

namespace App\Domains\Payment\Models;

use App\Domains\Booking\Enums\PaymentType;
use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\PaymentTransactionStatus;
use App\Domains\Payment\Enums\PaymentTransactionType;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'booking_id', 'user_id', 'payment_method_id', 'payment_type', 'provider', 'provider_ref',
    'amount_piastres', 'platform_fee_piastres', 'driver_amount_piastres', 'type',
    'idempotency_key', 'retry_count',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'payment_type' => PaymentType::class,
            'amount_piastres' => 'integer',
            'platform_fee_piastres' => 'integer',
            'driver_amount_piastres' => 'integer',
            'type' => PaymentTransactionType::class,
            'status' => PaymentTransactionStatus::class,
            'authorized_at' => 'datetime',
            'captured_at' => 'datetime',
            'confirmed_by_driver_at' => 'datetime',
            'retry_count' => 'integer',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function isCash(): bool
    {
        return $this->payment_type === PaymentType::Cash;
    }
}
