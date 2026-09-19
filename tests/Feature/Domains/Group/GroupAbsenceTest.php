<?php

use App\Domains\Group\Models\GroupAbsence;
use Illuminate\Database\QueryException;

it('rejects an absence range where to_date is before from_date', function () {
    GroupAbsence::factory()->create([
        'from_date' => '2026-08-20',
        'to_date' => '2026-08-10',
    ]);
})->throws(QueryException::class);

it('allows a single-day absence where to_date equals from_date', function () {
    $absence = GroupAbsence::factory()->create([
        'from_date' => '2026-08-20',
        'to_date' => '2026-08-20',
    ]);

    expect($absence->exists)->toBeTrue();
});

it('can release the seat to the waitlist', function () {
    $absence = GroupAbsence::factory()->create(['releases_seat' => true]);

    expect($absence->releases_seat)->toBeTrue();
});
