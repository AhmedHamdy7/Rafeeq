<?php

use App\Domains\Geo\Models\RouteCache;

it('uses cache_key as its primary key', function () {
    $route = RouteCache::factory()->create();

    expect($route->getKeyName())->toBe('cache_key')
        ->and($route->getKey())->toBe($route->cache_key);
});

it('reports expiry correctly', function () {
    $fresh = RouteCache::factory()->create();
    $expired = RouteCache::factory()->expired()->create();

    expect($fresh->isExpired())->toBeFalse()
        ->and($expired->isExpired())->toBeTrue();
});

it('tracks cache hits', function () {
    $route = RouteCache::factory()->create(['hit_count' => 0]);

    $route->recordHit();

    expect($route->fresh()->hit_count)->toBe(1);
});
