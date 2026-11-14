<?php

use App\Console\Commands\SeedGreaterCairoPlaces;
use App\Domains\Geo\Enums\PlaceType;
use App\Domains\Geo\Models\Place;
use App\Domains\Geo\Models\PlaceSearchMiss;
use App\Domains\Shared\ValueObjects\Coordinate;

beforeEach(function () {
    $this->token = passenger('01112223344');
});

function searchablePlace(string $name, float $lat, float $lng, array $overrides = []): Place
{
    return Place::factory()->create([
        'name' => $name,
        'name_ar' => $overrides['name_ar'] ?? 'مكان '.$name,
        'lat' => $lat,
        'lng' => $lng,
        'point' => new Coordinate($lat, $lng),
        'usage_count' => 0,
        ...$overrides,
    ]);
}

function placesFor(string $query, string $language = 'en'): array
{
    return test()->flushHeaders()->withToken(test()->token)->withHeader('Accept-Language', $language)
        ->getJson('/api/v1/places?'.$query)
        ->assertOk()
        ->json('data');
}

const REHAB_GATE_6 = 'lat=30.0626&lng=31.4913';

it('finds a matching place far from the driver when searching by name', function () {
    $smartVillage = searchablePlace('Smart Village B6', 30.0712, 31.0175);

    $places = placesFor('q=smart&'.REHAB_GATE_6);

    expect(collect($places)->pluck('id')->all())->toBe([$smartVillage->id])
        ->and($places[0]['distanceMetres'])->toBeGreaterThan(30_000);
});

it('puts the nearer of two equally good matches first', function () {
    $far = searchablePlace('Madinaty Gate 1', 30.1070, 31.5700, ['usage_count' => 9999]);
    $near = searchablePlace('Rehab Gate 2', 30.0650, 31.5020);

    $places = placesFor('q=gate&'.REHAB_GATE_6);

    expect(collect($places)->pluck('id')->all())->toBe([$near->id, $far->id]);
});

it('ranks a name that starts with the term above a nearer name that only contains it', function () {
    $contains = searchablePlace('Rehab Gate 2', 30.0630, 31.4915);
    $starts = searchablePlace('Gate Plaza', 30.0300, 31.4100);

    $places = placesFor('q=gate&'.REHAB_GATE_6);

    expect(collect($places)->pluck('id')->all())->toBe([$starts->id, $contains->id]);
});

it('breaks a tie on match and distance with how often a place is used', function () {
    $quiet = searchablePlace('Gate One', 30.0626, 31.4913, ['usage_count' => 1]);
    $busy = searchablePlace('Gate Two', 30.0626, 31.4913, ['usage_count' => 50]);

    expect(collect(placesFor('q=gate&'.REHAB_GATE_6))->pluck('id')->all())->toBe([$busy->id, $quiet->id]);
});

it('orders matches by quality then use when no location is sent', function () {
    $area = searchablePlace('Somewhere', 30.0626, 31.4913, ['name_ar' => 'مكان', 'district' => 'Gateway District', 'usage_count' => 9000]);
    $contains = searchablePlace('Rehab Gate 2', 30.0630, 31.4915, ['usage_count' => 5000]);
    $startsQuiet = searchablePlace('Gate Plaza', 30.0300, 31.4100, ['usage_count' => 1]);
    $startsBusy = searchablePlace('Gate Mall', 29.9000, 31.2000, ['usage_count' => 10]);

    $places = placesFor('q=gate');

    expect(collect($places)->pluck('id')->all())->toBe([$startsBusy->id, $startsQuiet->id, $contains->id, $area->id])
        ->and($places[0])->not->toHaveKey('distanceMetres');
});

it('returns at most the limit asked for', function () {
    foreach (range(1, 10) as $i) {
        searchablePlace("Gate {$i}", 30.0626 + $i / 1000, 31.4913);
    }

    expect(placesFor('q=gate&limit=8&'.REHAB_GATE_6))->toHaveCount(8)
        ->and(placesFor('q=gate'))->toHaveCount(10);
});

it('refuses a limit outside 1 to 20', function (int $limit) {
    test()->withToken($this->token)->getJson("/api/v1/places?q=gate&limit={$limit}")->assertStatus(422);
})->with([0, 21]);

it('still limits a name search by distance when the radius is sent', function () {
    $near = searchablePlace('Rehab Gate 2', 30.0650, 31.5020);
    searchablePlace('Smart Village Gate', 30.0712, 31.0175);

    $places = placesFor('q=gate&radiusMetres=5000&'.REHAB_GATE_6);

    expect(collect($places)->pluck('id')->all())->toBe([$near->id]);
});

it('keeps the places-near-a-point list as it was when no term is sent', function () {
    $farther = searchablePlace('Rehab Gate 8', 30.0700, 31.5100, ['usage_count' => 9999]);
    $nearest = searchablePlace('Rehab Gate 6', 30.0627, 31.4914);
    searchablePlace('Smart Village', 30.0712, 31.0175, ['usage_count' => 9999]);

    $places = placesFor(REHAB_GATE_6);

    expect(collect($places)->pluck('id')->all())->toBe([$nearest->id, $farther->id]);
});

it('sends distanceMetres on every row only when a location is sent', function () {
    searchablePlace('Gate A', 30.0650, 31.5020);
    searchablePlace('Gate B', 30.0712, 31.0175);

    $withLocation = placesFor('q=gate&'.REHAB_GATE_6);
    $without = placesFor('q=gate');

    expect(collect($withLocation)->every(fn (array $row): bool => is_int($row['distanceMetres'] ?? null)))->toBeTrue()
        ->and(collect($without)->every(fn (array $row): bool => ! array_key_exists('distanceMetres', $row)))->toBeTrue();
});

it('never returns a private place', function () {
    searchablePlace('Gate Private', 30.0626, 31.4913, ['is_public' => false]);
    $public = searchablePlace('Gate Public', 30.0626, 31.4913);

    expect(collect(placesFor('q=gate&'.REHAB_GATE_6))->pluck('id')->all())->toBe([$public->id])
        ->and(collect(placesFor('q=gate'))->pluck('id')->all())->toBe([$public->id])
        ->and(collect(placesFor(REHAB_GATE_6))->pluck('id')->all())->toBe([$public->id]);
});

it('treats LIKE wildcards in the term as text', function () {
    searchablePlace('Gate 1', 30.0626, 31.4913);

    expect(placesFor('q='.urlencode('%')))->toBeEmpty()
        ->and(placesFor('q='.urlencode('_')))->toBeEmpty();
});

it('returns the district and city in English when asked in English', function () {
    searchablePlace('Rehab Gate 6', 30.0626, 31.4913, ['district' => 'الرحاب', 'city' => 'القاهرة']);

    $place = placesFor('q=gate', 'en')[0];

    expect($place['district'])->toBe('Rehab')
        ->and($place['city'])->toBe('Cairo');
});

it('returns the district and city as stored when asked in Arabic', function () {
    searchablePlace('Rehab Gate 6', 30.0626, 31.4913, ['district' => 'الرحاب', 'city' => 'القاهرة']);

    $place = placesFor('q=gate', 'ar')[0];

    expect($place['district'])->toBe('الرحاب')
        ->and($place['city'])->toBe('القاهرة');
});

it('returns a district with no English name as stored', function () {
    searchablePlace('Gate 1', 30.0626, 31.4913, ['district' => 'حي بلا ترجمة']);

    expect(placesFor('q=gate', 'en')[0]['district'])->toBe('حي بلا ترجمة');
});

it('has an English name for every district and city in the Greater Cairo catalogue', function () {
    $places = (new ReflectionClass(SeedGreaterCairoPlaces::class))->getConstant('PLACES');
    $english = json_decode((string) file_get_contents(lang_path('en.json')), true);

    $areas = collect($places)->flatMap(fn (array $place): array => [$place[5], $place[6]])->unique();
    $missing = $areas->reject(fn (string $area): bool => isset($english[$area]))->values()->all();

    expect($areas)->not->toBeEmpty()
        ->and($missing)->toBe([]);
});

it('matches a place by the English name of its area', function () {
    $named = searchablePlace('Rehab Mall', 30.0700, 31.5000);
    $inArea = searchablePlace('Gate 6', 30.0626, 31.4913, ['name_ar' => 'البوابة 6', 'district' => 'الرحاب']);
    searchablePlace('Gate 9', 30.0626, 31.4913, ['name_ar' => 'البوابة 9', 'district' => 'المعادي']);

    expect(collect(placesFor('q=rehab&'.REHAB_GATE_6))->pluck('id')->all())->toBe([$named->id, $inArea->id]);
});

it('matches a place by its city', function () {
    $giza = searchablePlace('Office 1', 30.0262, 31.2088, ['name_ar' => 'مكتب', 'city' => 'الجيزة']);
    searchablePlace('Office 2', 30.0626, 31.4913, ['name_ar' => 'مكتب', 'city' => 'القاهرة']);

    expect(collect(placesFor('q='.urlencode('الجيزة')))->pluck('id')->all())->toBe([$giza->id])
        ->and(collect(placesFor('q=giza'))->pluck('id')->all())->toBe([$giza->id]);
});

it('forgives Arabic spelling variants', function (string $typed, string $stored) {
    $place = searchablePlace('Somewhere', 30.0626, 31.4913, ['name_ar' => $stored]);

    expect(collect(placesFor('q='.urlencode($typed), 'ar'))->pluck('id')->all())->toBe([$place->id]);
})->with([
    'ta marbuta and ya' => ['القريه الذكيه', 'القرية الذكية'],
    'hamza on alef' => ['احمد', 'أحمد'],
    'alef maqsura' => ['مصطفي', 'مصطفى'],
    'tashkeel typed' => ['مَدِينَتِي', 'مدينتي'],
]);

it('finds a place by an alias', function (string $typed) {
    $place = searchablePlace('Fifth Settlement', 30.0074, 31.4913, [
        'name_ar' => 'التجمع الخامس',
        'aliases' => ['tagamo3', '5th Settlement', 'التجمع'],
    ]);
    searchablePlace('Maadi', 29.9602, 31.2569, ['name_ar' => 'المعادي']);

    expect(collect(placesFor('q='.urlencode($typed), 'ar'))->pluck('id')->all())->toBe([$place->id]);
})->with(['tagamo3', 'TAGAMO3', '5th settlement', 'التجمع', '٥th settlement']);

it('reads Arabic digits as English digits', function () {
    $place = searchablePlace('Gate 5', 30.0626, 31.4913, ['name_ar' => 'بوابة 5']);

    expect(collect(placesFor('q='.urlencode('بوابة ٥'), 'ar'))->pluck('id')->all())->toBe([$place->id]);
});

it('logs a search that finds nothing', function () {
    searchablePlace('Maadi', 29.9602, 31.2569, ['name_ar' => 'المعادي']);

    expect(placesFor('q='.urlencode('مدينة بدر').'&'.REHAB_GATE_6, 'ar'))->toBe([]);
    placesFor('q=maadi');

    $miss = PlaceSearchMiss::query()->sole();

    expect($miss->query)->toBe('مدينة بدر')
        ->and($miss->normalised_query)->toBe('مدينه بدر')
        ->and($miss->lat)->toBe(30.06)
        ->and($miss->lng)->toBe(31.49)
        ->and($miss->user_id)->not->toBeNull();
});

it('marks areas and gives their radius', function () {
    searchablePlace('Nasr City', 30.0566, 31.3301, ['name_ar' => 'مدينة نصر', 'type' => PlaceType::District]);
    searchablePlace('Nasr Gate', 30.0570, 31.3310, ['name_ar' => 'بوابة نصر', 'type' => PlaceType::CompoundGate]);

    $places = collect(placesFor('q=nasr'))->keyBy('name');

    expect($places['Nasr City']['isArea'])->toBeTrue()
        ->and($places['Nasr City']['radiusMetres'])->toBe(4000)
        ->and($places['Nasr Gate']['isArea'])->toBeFalse()
        ->and($places['Nasr Gate']['radiusMetres'])->toBeNull();
});

it('refuses a place with no Arabic name', function () {
    Place::factory()->create(['name_ar' => ' ']);
})->throws(InvalidArgumentException::class);
