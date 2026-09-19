<?php

use App\Domains\Identity\Models\User;
use App\Domains\Matching\Models\SavedSearch;
use Illuminate\Database\QueryException;

it('rejects saving the exact same filters twice for the same user', function () {
    $user = User::factory()->create();
    $filters = ['origin' => 'Rehab', 'women_only' => true];

    SavedSearch::factory()->for($user)->create(['filters' => $filters, 'signature' => SavedSearch::signatureFor($filters)]);
    SavedSearch::factory()->for($user)->create(['filters' => $filters, 'signature' => SavedSearch::signatureFor($filters)]);
})->throws(QueryException::class);

it('computes the same signature regardless of filter key order', function () {
    $a = SavedSearch::signatureFor(['origin' => 'Rehab', 'women_only' => true]);
    $b = SavedSearch::signatureFor(['women_only' => true, 'origin' => 'Rehab']);

    expect($a)->toBe($b);
});
