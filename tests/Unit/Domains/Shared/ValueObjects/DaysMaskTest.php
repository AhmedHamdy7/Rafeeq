<?php

use App\Domains\Shared\ValueObjects\DaysMask;
use Carbon\CarbonImmutable;

it('combines individual day bits', function () {
    $mask = DaysMask::fromDays([DaysMask::SUNDAY, DaysMask::MONDAY]);

    expect($mask->value)->toBe(DaysMask::SUNDAY | DaysMask::MONDAY)
        ->and($mask->includes(DaysMask::SUNDAY))->toBeTrue()
        ->and($mask->includes(DaysMask::MONDAY))->toBeTrue()
        ->and($mask->includes(DaysMask::FRIDAY))->toBeFalse();
});

it('builds the Sunday-to-Thursday work week helper used across the ERD examples', function () {
    // Bible §Part 2, Noor's schedule: أحد إثنين تلات أربع خميس = 62.
    expect(DaysMask::weekdaysSunToThu()->value)->toBe(62);
});

it('rejects a mask outside the 7-bit range', function () {
    DaysMask::fromBits(128);
})->throws(InvalidArgumentException::class);

it('maps a Carbon date to the correct bit, Saturday=1 through Friday=64', function (string $date, int $expectedBit) {
    expect(DaysMask::bitForDate(CarbonImmutable::parse($date)))->toBe($expectedBit);
})->with([
    'Saturday 2026-08-08' => ['2026-08-08', DaysMask::SATURDAY],
    'Sunday 2026-08-09' => ['2026-08-09', DaysMask::SUNDAY],
    'Monday 2026-08-10' => ['2026-08-10', DaysMask::MONDAY],
    'Tuesday 2026-08-11' => ['2026-08-11', DaysMask::TUESDAY],
    'Wednesday 2026-08-12' => ['2026-08-12', DaysMask::WEDNESDAY],
    'Thursday 2026-08-13' => ['2026-08-13', DaysMask::THURSDAY],
    'Friday 2026-08-14' => ['2026-08-14', DaysMask::FRIDAY],
]);

it('checks whether a mask includes a given date', function () {
    $mask = DaysMask::weekdaysSunToThu();

    expect($mask->includesDate(CarbonImmutable::parse('2026-08-09')))->toBeTrue()  // Sunday
        ->and($mask->includesDate(CarbonImmutable::parse('2026-08-08')))->toBeFalse(); // Saturday
});
