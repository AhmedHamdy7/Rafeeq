<?php

use App\Domains\Payment\Models\PaymentWebhook;
use Illuminate\Database\QueryException;

it('rejects a replayed event for the same provider and event_id', function () {
    $webhook = PaymentWebhook::factory()->create();

    PaymentWebhook::factory()->create([
        'provider' => $webhook->provider,
        'event_id' => $webhook->event_id,
    ]);
})->throws(QueryException::class);

it('allows the same event_id across different providers', function () {
    $webhook = PaymentWebhook::factory()->create(['provider' => 'paymob']);

    $other = PaymentWebhook::factory()->create([
        'provider' => 'stripe',
        'event_id' => $webhook->event_id,
    ]);

    expect($other->exists)->toBeTrue();
});

it('reports processed state correctly', function () {
    $pending = PaymentWebhook::factory()->create();
    $processed = PaymentWebhook::factory()->processed()->create();

    expect($pending->isProcessed())->toBeFalse()
        ->and($processed->isProcessed())->toBeTrue();
});
