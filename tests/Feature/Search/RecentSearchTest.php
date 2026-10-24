<?php

use App\Domains\Identity\Models\User;
use App\Domains\Matching\Actions\RecordRecentSearchAction;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Matching\Models\SavedSearch;
use App\Domains\Matching\Models\SearchHistory;
use App\Domains\Matching\Support\SearchCriteria;
use App\Domains\Shared\ValueObjects\DaysMask;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Recent searches — the chips under the search box (Feature 03 v5 §3 and its `search_history`).
 *
 * > *"On search-field focus show account-synced Recent Searches + `Clear All`; Clear All removes
 * > history, not demands."* and *"Search alone doesn't create a demand."*
 *
 * 🔴 The feature is three sentences and two of them are boundaries. A recent search is a
 * convenience nobody asked for; a saved search is one she named; a demand is a standing instruction
 * that makes the platform notify her. Recording the first must not create either of the others, and
 * clearing the first must not touch them.
 *
 * 🔒 And what is stored is where somebody lives and where she works, with timestamps — so it is
 * readable by nobody but her, erasable in one call, and it ages out on its own.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->token = verifiedPassenger('01112223344', device: 'pax-1');
});

it('remembers a search she ran', function () {
    search($this->token)->assertOk();

    $entry = SearchHistory::query()->sole();

    test()->withToken($this->token)
        ->getJson('/api/v1/me/recent-searches')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $entry->getKey())
        /*
         * 🔴 The criteria come back exactly as she sent them, so the client re-runs the search by
         * posting the same object back. A rendered summary would make the list readable and
         * useless — the one thing a recent search is for is being tapped.
         */
        ->assertJsonPath('data.0.criteria.daysMask', DaysMask::weekdaysSunToThu()->value)
        ->assertJsonPath('data.0.criteria.arrivalWindowStart', '06:45:00')
        ->assertJsonPath('data.0.criteria.origin.lat', 30.0594);
});

/**
 * 🔴 Searching the same thing five times in a morning is one entry, not five — otherwise the list
 * is nothing but her last few keystrokes.
 */
it('keeps one entry however many times she runs the same search', function () {
    foreach (range(1, 4) as $ignored) {
        search($this->token)->assertOk();
    }

    expect(SearchHistory::query()->count())->toBe(1);
});

/**
 * And the repeat moves it back to the top, because that is what the order is for.
 */
it('moves a repeated search to the top without resetting its age', function () {
    search($this->token)->assertOk();

    $first = SearchHistory::query()->sole();

    // An older entry it has to overtake.
    $first->forceFill([
        'searched_at' => now()->subDays(10),
        'created_at' => now()->subDays(10),
    ])->save();

    search($this->token, ['maxWalkMinutes' => 10])->assertOk();

    expect(SearchHistory::query()->count())->toBe(2)
        ->and(SearchHistory::query()->orderByDesc('searched_at')->first()->getKey())
        ->not->toBe($first->getKey());

    search($this->token)->assertOk();

    $repeated = SearchHistory::query()->whereKey($first->getKey())->sole();

    expect(SearchHistory::query()->orderByDesc('searched_at')->first()->getKey())
        ->toBe($first->getKey())
        /*
         * 🔒 `created_at` did NOT move. Retention measures from there, so bumping it would mean a
         * route she re-runs every week is never pruned — and this is the one table where "kept for
         * ever" is a privacy decision rather than a storage one.
         */
        ->and($repeated->created_at->toDateString())->toBe(now()->subDays(10)->toDateString());
});

it('lists the newest first', function () {
    /*
     * A second apart, because `searched_at` has second precision: three searches inside one second
     * are ordered by the id tiebreak, which is fine in production and not something a test should
     * assert.
     */
    search($this->token)->assertOk();
    $this->travel(1)->second();
    search($this->token, ['maxWalkMinutes' => 10])->assertOk();
    $this->travel(1)->second();
    search($this->token, ['maxWalkMinutes' => 5])->assertOk();

    $listed = test()->withToken($this->token)
        ->getJson('/api/v1/me/recent-searches')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->json('data');

    expect($listed[0]['criteria']['maxWalkMinutes'])->toBe(5)
        ->and($listed[2]['criteria']['maxWalkMinutes'])->toBe(15);
});

/**
 * 🔒 A bound rather than a growing log, and the reason is privacy more than storage: "we keep your
 * last twenty searches" is a sentence that can be said out loud.
 */
it('keeps only the most recent few', function () {
    config(['rafeeq.matching.recent_searches_kept' => 3]);

    foreach ([15, 14, 13, 12, 11] as $walk) {
        search($this->token, ['maxWalkMinutes' => $walk])->assertOk();
        $this->travel(1)->second();
    }

    expect(SearchHistory::query()->count())->toBe(3)
        ->and(SearchHistory::query()->orderByDesc('searched_at')->first()->criteria['maxWalkMinutes'])
        ->toBe(11);
});

/**
 * 🔴 v5 says this twice: "search alone doesn't create a demand", "do not auto-save demand". A
 * search that quietly created either would make the platform start notifying her about something
 * she never signed up for.
 */
it('creates no demand and no saved search', function () {
    search($this->token)->assertOk();

    expect(CommuteDemand::query()->count())->toBe(0)
        ->and(SavedSearch::query()->count())->toBe(0)
        ->and(SearchHistory::query()->count())->toBe(1);
});

/**
 * 🔴 `Clear All` clears history and nothing else. A button that erased her saved searches and her
 * demands because she wanted her search box tidy would delete work she cannot get back — including
 * a standing instruction she may be relying on.
 */
it('clears the history and leaves her demands and saved searches alone', function () {
    search($this->token)->assertOk();

    $demandId = test()->flushHeaders()->withToken($this->token)
        ->postJson('/api/v1/commute-demands', searchCriteria())
        ->assertStatus(201)->json('data.id');

    $savedId = test()->flushHeaders()->withToken($this->token)
        ->postJson('/api/v1/saved-searches', [
            'title' => 'الرحاب ← سمارت فيلدج',
            'filters' => searchCriteria(),
        ])->assertStatus(201)->json('data.id');

    test()->flushHeaders()->withToken($this->token)
        ->deleteJson('/api/v1/me/recent-searches')
        ->assertOk()
        ->assertJsonPath('data.cleared', 1);

    expect(SearchHistory::query()->count())->toBe(0)
        // 🔴 Both still here.
        ->and(CommuteDemand::query()->whereKey($demandId)->exists())->toBeTrue()
        ->and(SavedSearch::query()->whereKey($savedId)->exists())->toBeTrue();
});

it('clears nothing when there is nothing to clear', function () {
    test()->withToken($this->token)
        ->deleteJson('/api/v1/me/recent-searches')
        ->assertOk()
        ->assertJsonPath('data.cleared', 0);
});

/**
 * 🔒 Hers alone. There is no id and no parameter on either endpoint, and nothing anywhere reads
 * another person's list — so the test is that one person's search is invisible to the other and
 * survives the other clearing hers.
 */
it('keeps one person’s searches out of another’s list', function () {
    search($this->token)->assertOk();

    $other = verifiedPassenger('01299887766', device: 'pax-9');

    test()->flushHeaders()->withToken($other)
        ->getJson('/api/v1/me/recent-searches')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    test()->flushHeaders()->withToken($other)
        ->deleteJson('/api/v1/me/recent-searches')->assertOk();

    // Hers is untouched by somebody else's Clear All.
    expect(SearchHistory::query()->count())->toBe(1);
});

/**
 * 🔴 The search is the thing she asked for; remembering it is a convenience on top. A failure in the
 * second must never cost her the first — so the recording swallows its own errors, and logs them so
 * they stay findable.
 *
 * Provoked with a user row that no longer exists, which makes the insert fail on its foreign key.
 * That is a real failure the database raises, rather than a mock agreeing with the test.
 */
it('never lets a failed recording break the search', function () {
    Log::spy();

    // A user id that was never saved, so the insert fails on its foreign key. A real failure the
    // database raises, rather than a mock agreeing with the test.
    $ghost = new User;
    $ghost->forceFill(['id' => (string) Str::ulid()]);

    $criteria = SearchCriteria::fromArray(searchCriteria());

    // No exception reaches the caller.
    app(RecordRecentSearchAction::class)->execute($ghost, $criteria);

    expect(SearchHistory::query()->count())->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'recent search'))
        ->once();
});
