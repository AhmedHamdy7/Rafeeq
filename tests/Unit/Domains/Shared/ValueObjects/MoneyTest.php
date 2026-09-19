<?php

use App\Domains\Shared\ValueObjects\Money;

it('never uses floats internally', function () {
    $money = Money::fromPounds(88.20);

    expect($money->piastres)->toBe(8820);
});

it('adds two amounts exactly, avoiding float drift', function () {
    $total = Money::fromPounds(88.20)->plus(Money::fromPounds(88.20))->plus(Money::fromPounds(88.20));

    // 88.20 * 3 as a float is 264.59999999999997 — this must be exact.
    expect($total->piastres)->toBe(26460);
});

it('rejects negative amounts', function () {
    Money::fromPiastres(-1);
})->throws(InvalidArgumentException::class);

it('computes a percentage rounded to the nearest piastre', function () {
    $fee = Money::fromPiastres(8800)->percentage(3.0);

    expect($fee->piastres)->toBe(264);
});

it('formats as Egyptian pounds', function () {
    expect(Money::fromPiastres(8800)->format())->toBe('88.00 ج.م');
});

it('treats zero as zero', function () {
    expect(Money::zero()->isZero())->toBeTrue();
    expect(Money::fromPiastres(1)->isZero())->toBeFalse();
});
