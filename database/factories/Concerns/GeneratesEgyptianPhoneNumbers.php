<?php

namespace Database\Factories\Concerns;

trait GeneratesEgyptianPhoneNumbers
{
    /**
     * A random but always-valid local Egyptian mobile number (010/011/012/015).
     */
    protected static function fakeEgyptianMobile(): string
    {
        $validPrefixSecondDigit = fake()->randomElement(['0', '1', '2', '5']);

        return '01'.$validPrefixSecondDigit.fake()->numerify('########');
    }
}
