<?php

use App\Domains\Geo\Models\Corridor;
use App\Domains\Geo\Models\CorridorStat;
use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Database\QueryException;

it('exposes its days_mask as a DaysMask value object', function () {
    $corridor = Corridor::factory()->create();

    expect($corridor->daysMask())->toBeInstanceOf(DaysMask::class)
        ->and($corridor->daysMask()->includes(DaysMask::SUNDAY))->toBeTrue()
        ->and($corridor->daysMask()->includes(DaysMask::FRIDAY))->toBeFalse();
});

it('loads distinct origin and destination places', function () {
    $corridor = Corridor::factory()->create();

    expect($corridor->originPlace->id)->not->toBe($corridor->destinationPlace->id);
});

it('rejects a second stats snapshot for the same corridor on the same day', function () {
    $corridor = Corridor::factory()->create();

    CorridorStat::factory()->for($corridor)->create(['stat_date' => '2026-08-09']);
    CorridorStat::factory()->for($corridor)->create(['stat_date' => '2026-08-09']);
})->throws(QueryException::class);
