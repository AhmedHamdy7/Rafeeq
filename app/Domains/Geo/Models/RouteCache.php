<?php

namespace App\Domains\Geo\Models;

use Database\Factories\RouteCacheFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Pure cache layer, no FKs (Bible §17) — `cache_key` is a
 * sha256 of the 4-decimal-rounded origin/destination/waypoints/mode, which
 * is what makes nearby searches share a row instead of re-billing Google.
 */
#[Fillable([
    'cache_key', 'origin_lat', 'origin_lng', 'dest_lat', 'dest_lng',
    'polyline', 'distance_meters', 'duration_seconds',
    'duration_in_traffic_seconds', 'provider', 'fetched_at', 'expires_at',
])]
class RouteCache extends Model
{
    /** @use HasFactory<RouteCacheFactory> */
    use HasFactory;

    // Eloquent would otherwise guess "route_caches" — the ERD names this
    // table singular since it's a cache store, not a pluralized collection.
    protected $table = 'route_cache';

    protected $primaryKey = 'cache_key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'origin_lat' => 'decimal:4',
            'origin_lng' => 'decimal:4',
            'dest_lat' => 'decimal:4',
            'dest_lng' => 'decimal:4',
            'distance_meters' => 'integer',
            'duration_seconds' => 'integer',
            'duration_in_traffic_seconds' => 'integer',
            'fetched_at' => 'datetime',
            'expires_at' => 'datetime',
            'hit_count' => 'integer',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function recordHit(): void
    {
        $this->increment('hit_count');
    }
}
