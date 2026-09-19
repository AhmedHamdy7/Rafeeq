<?php

use App\Domains\Rating\Models\Rating;
use Illuminate\Database\QueryException;

it('rejects a second rating from the same reviewer for the same booking', function () {
    $rating = Rating::factory()->create();

    Rating::factory()->create([
        'booking_id' => $rating->booking_id,
        'reviewer_user_id' => $rating->reviewer_user_id,
    ]);
})->throws(QueryException::class);

it('rejects a star value outside 1 to 5', function () {
    Rating::factory()->create(['stars' => 6]);
})->throws(QueryException::class);

it('hides a rating until visible_at is set — double blind', function () {
    $hidden = Rating::factory()->create();
    $visible = Rating::factory()->visible()->create();

    expect($hidden->isVisible())->toBeFalse()
        ->and($visible->isVisible())->toBeTrue();
});

it('excludes hidden ratings when the visible scope is applied', function () {
    Rating::factory()->create();
    $visible = Rating::factory()->visible()->create();

    $results = Rating::visible()->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($visible->id);
});

it('still lets a reviewer see their own submitted rating regardless of visibility', function () {
    $rating = Rating::factory()->create();

    // Not using the visible() scope — this is "my own rating I submitted".
    expect(Rating::find($rating->id))->not->toBeNull();
});
