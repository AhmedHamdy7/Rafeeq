<?php

namespace App\Domains\Geo\Actions;

use App\Domains\Geo\Enums\PlaceSuggestionKind;
use App\Domains\Geo\Models\PlaceSuggestion;
use App\Domains\Geo\Support\GooglePlaceDetails;

final readonly class RefreshPlaceCoordinatesAction
{
    public function __construct(private GooglePlaceDetails $details) {}

    /**
     * @return array{refreshed: int, dropped: int}
     */
    public function execute(int $limit): array
    {
        $keep = now()->subDays((int) config('rafeeq.places.google_coords_keep_days'));
        $refreshAfter = now()->subDays((int) config('rafeeq.places.google_coords_refresh_days'));

        $refreshed = 0;
        $dropped = 0;

        PlaceSuggestion::query()
            ->where('kind', PlaceSuggestionKind::GooglePlace->value)
            ->whereNotNull('lat')
            ->where(fn ($query) => $query->whereNull('coords_fetched_at')->orWhere('coords_fetched_at', '<', $refreshAfter))
            ->orderBy('coords_fetched_at')
            ->limit($limit)
            ->get()
            ->each(function (PlaceSuggestion $row) use ($keep, &$refreshed, &$dropped): void {
                $usedRecently = $row->last_used_at !== null && $row->last_used_at->greaterThanOrEqualTo($keep);
                $fresh = $usedRecently && $row->google_place_id !== null
                    ? $this->details->location($row->google_place_id)
                    : null;

                if ($fresh !== null) {
                    $row->forceFill(['lat' => $fresh->lat, 'lng' => $fresh->lng, 'coords_fetched_at' => now()])->save();
                    $refreshed++;

                    return;
                }

                if (! $usedRecently || $row->coords_fetched_at === null || $row->coords_fetched_at->lessThan($keep)) {
                    $row->forceFill(['lat' => null, 'lng' => null, 'coords_fetched_at' => null])->save();
                    $dropped++;
                }
            });

        return ['refreshed' => $refreshed, 'dropped' => $dropped];
    }
}
