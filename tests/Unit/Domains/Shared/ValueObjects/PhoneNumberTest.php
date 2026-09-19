<?php

use App\Domains\Shared\ValueObjects\PhoneNumber;

it('normalizes a local Egyptian number to E.164', function () {
    expect(PhoneNumber::fromRaw('01012345678')->e164)->toBe('+201012345678');
});

it('accepts a number already in E.164 form', function () {
    expect(PhoneNumber::fromRaw('+201012345678')->e164)->toBe('+201012345678');
});

it('accepts the 00 country-code prefix and stray formatting characters', function () {
    expect(PhoneNumber::fromRaw('00201012345678')->e164)->toBe('+201012345678');
    expect(PhoneNumber::fromRaw('010 1234 5678')->e164)->toBe('+201012345678');
    expect(PhoneNumber::fromRaw('010-123-45678')->e164)->toBe('+201012345678');
});

it('normalizes Arabic-Indic and Eastern Arabic-Indic digits before validating', function () {
    expect(PhoneNumber::fromRaw('٠١٠١٢٣٤٥٦٧٨')->e164)->toBe('+201012345678');
    expect(PhoneNumber::fromRaw('۰۱۰۱۲۳۴۵۶۷۸')->e164)->toBe('+201012345678');
});

it('accepts every valid Egyptian mobile prefix', function (string $prefix) {
    expect(PhoneNumber::fromRaw($prefix.'12345678')->e164)->toBe('+2'.$prefix.'12345678');
})->with(['010', '011', '012', '015']);

it('rejects a number that is not a valid Egyptian mobile number', function (string $raw) {
    PhoneNumber::fromRaw($raw);
})->with([
    '01312345678',   // invalid prefix
    '0123456',       // too short
    'not-a-number',
])->throws(InvalidArgumentException::class);

it('compares two phone numbers by value', function () {
    $a = PhoneNumber::fromRaw('01012345678');
    $b = PhoneNumber::fromRaw('+201012345678');

    expect($a->equals($b))->toBeTrue();
    expect((string) $a)->toBe('+201012345678');
});
