<?php

use App\Domains\Payment\Models\Payment;
use Illuminate\Database\QueryException;

it('rejects a duplicate idempotency key — the double-charge guard', function () {
    $payment = Payment::factory()->create();

    Payment::factory()->create(['idempotency_key' => $payment->idempotency_key]);
})->throws(QueryException::class);

it('keeps the amount split exactly consistent', function () {
    $payment = Payment::factory()->create();

    expect($payment->amount_piastres)->toBe($payment->platform_fee_piastres + $payment->driver_amount_piastres);
});

it('rejects a payment whose split does not add up', function () {
    Payment::factory()->create([
        'amount_piastres' => 8800,
        'platform_fee_piastres' => 240,
        'driver_amount_piastres' => 8000,
        'idempotency_key' => 'mismatched-split',
    ]);
})->throws(QueryException::class);

it('distinguishes cash and online payments', function () {
    $cash = Payment::factory()->create();
    $online = Payment::factory()->online()->create();

    expect($cash->isCash())->toBeTrue()
        ->and($online->isCash())->toBeFalse();
});
