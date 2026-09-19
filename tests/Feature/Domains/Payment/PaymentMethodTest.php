<?php

use App\Domains\Payment\Models\PaymentMethod;

it('hides the provider token from serialization', function () {
    $method = PaymentMethod::factory()->create();

    expect($method->toArray())->not->toHaveKey('provider_token');
});
