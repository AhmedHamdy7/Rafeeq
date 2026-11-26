<?php

namespace App\Domains\Geo\Support;

use App\Domains\Geo\Enums\PlaceSuggestionKind;
use App\Domains\Geo\Models\Area;
use App\Domains\Geo\Models\PlacePinPick;
use App\Domains\Geo\Models\PlaceSuggestion;
use App\Domains\Shared\ValueObjects\Coordinate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PlaceSuggestionBook
{
    /**
     * @param  list<LearnedPoint>  $points
     */
    public static function learnLater(array $points): void
    {
        if ($points === []) {
            return;
        }

        $locale = app()->getLocale();

        DB::afterCommit(function () use ($points, $locale): void {
            foreach ($points as $point) {
                try {
                    app(self::class)->learn($point, $locale);
                } catch (Throwable $e) {
                    Log::warning('places.suggestions.learn_failed', ['reason' => $e->getMessage()]);
                }
            }
        });
    }

    public function learn(LearnedPoint $point, ?string $locale = null): void
    {
        $locale ??= app()->getLocale();
        $label = self::cleanLabel($point->label);

        if ($point->isPublic()) {
            $this->learnPublic($point, $label, $locale);

            return;
        }

        if ($point->userId === null) {
            return;
        }

        $public = $this->nearest(PlaceSuggestion::query()->whereNotNull('lat'), $point->point, self::mergeMetres());

        if ($public !== null) {
            $this->touch($public, $point->role);

            return;
        }

        $this->learnPin($point, $label);
    }

    private function learnPublic(LearnedPoint $point, ?string $label, string $locale): void
    {
        $kind = $point->isCommutePoint ? PlaceSuggestionKind::CommutePoint : PlaceSuggestionKind::GooglePlace;

        $row = $point->googlePlaceId === null
            ? null
            : PlaceSuggestion::query()->where('google_place_id', $point->googlePlaceId)->first();

        $row ??= $this->nearest(
            PlaceSuggestion::query()
                ->whereNotNull('lat')
                ->when($point->googlePlaceId !== null, fn (Builder $query) => $query->whereNull('google_place_id')),
            $point->point,
            self::mergeMetres(),
        );
        $row ??= new PlaceSuggestion(['kind' => $kind, 'uses' => 0, 'roles' => 0]);

        if ($kind === PlaceSuggestionKind::CommutePoint && $row->kind !== PlaceSuggestionKind::CommutePoint) {
            $row->kind = PlaceSuggestionKind::CommutePoint;
            $row->coords_fetched_at = null;
        }

        if ($row->lat === null || $row->kind === PlaceSuggestionKind::GooglePlace) {
            $row->lat = $point->point->lat;
            $row->lng = $point->point->lng;
        }

        if ($row->kind === PlaceSuggestionKind::GooglePlace) {
            $row->coords_fetched_at = now();
        }

        $row->google_place_id ??= $point->googlePlaceId;
        $row->area_id ??= $point->areaId;
        $this->fillLabel($row, $label, $locale);

        $this->touch($row, $point->role);
    }

    private function learnPin(LearnedPoint $point, ?string $label): void
    {
        $pick = $this->nearest(PlacePinPick::query()->where('user_id', $point->userId), $point->point, self::mergeMetres())
            ?? new PlacePinPick(['user_id' => $point->userId, 'lat' => $point->point->lat, 'lng' => $point->point->lng, 'uses' => 0, 'roles' => 0]);

        $pick->label = $label ?? $pick->label;
        $pick->area_id ??= $point->areaId;
        $pick->roles = $pick->roles | $point->role;
        $pick->uses = $pick->uses + 1;
        $pick->last_used_at = now();
        $pick->save();

        $this->shareIfCommon($point);
    }

    private function shareIfCommon(LearnedPoint $point): void
    {
        $picks = $this->within(PlacePinPick::query(), $point->point, self::sharedMetres());

        if ($picks->pluck('user_id')->unique()->count() < self::sharedUsers()) {
            return;
        }

        $centre = new Coordinate(
            round((float) $picks->avg(fn (PlacePinPick $pick): float => (float) $pick->lat), 7),
            round((float) $picks->avg(fn (PlacePinPick $pick): float => (float) $pick->lng), 7),
        );

        $areaId = $picks->pluck('area_id')->filter()->countBy()->sortDesc()->keys()->first();
        $area = $areaId === null ? null : Area::query()->find($areaId);
        $label = $this->sharedLabel($picks);

        if ($label === null && $area === null) {
            return;
        }

        $row = $this->nearest(PlaceSuggestion::query()->where('kind', PlaceSuggestionKind::SharedPin->value), $centre, self::mergeMetres())
            ?? new PlaceSuggestion(['kind' => PlaceSuggestionKind::SharedPin, 'roles' => 0]);

        $row->lat = $centre->lat;
        $row->lng = $centre->lng;
        $row->area_id ??= $areaId;
        $row->label = $label ?? $area?->name_en ?? $area?->name_ar;
        $row->label_ar = $label === null ? ($area?->name_ar ?? $area?->name_en) : null;
        $row->roles = $picks->reduce(fn (int $carry, PlacePinPick $pick): int => $carry | $pick->roles, (int) $row->roles);
        $row->uses = (int) $picks->sum('uses');
        $row->last_used_at = now();
        $row->setRelation('area', $area);
        $row->refreshSearchText();
        $row->save();
    }

    /**
     * @param  Collection<int, PlacePinPick>  $picks
     */
    private function sharedLabel(Collection $picks): ?string
    {
        $byName = [];

        foreach ($picks as $pick) {
            $key = PlaceNameMatch::normalise($pick->label);

            if ($key === '') {
                continue;
            }

            $byName[$key]['label'] ??= $pick->label;
            $byName[$key]['users'][$pick->user_id] = true;
        }

        $best = null;

        foreach ($byName as $entry) {
            $users = count($entry['users']);

            if ($users >= 2 && ($best === null || $users > $best['users'])) {
                $best = ['label' => $entry['label'], 'users' => $users];
            }
        }

        return $best['label'] ?? null;
    }

    private function fillLabel(PlaceSuggestion $row, ?string $label, string $locale): void
    {
        if ($label === null) {
            return;
        }

        if ($locale === 'ar') {
            $row->label_ar ??= $label;
        } else {
            $row->label ??= $label;
        }
    }

    private function touch(PlaceSuggestion $row, int $role): void
    {
        $row->roles = ((int) $row->roles) | $role;
        $row->uses = ((int) $row->uses) + 1;
        $row->last_used_at = now();
        $row->load('area');
        $row->refreshSearchText();
        $row->save();
    }

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return T|null
     */
    private function nearest(Builder $query, Coordinate $point, int $metres): ?Model
    {
        return $this->within($query, $point, $metres)->first();
    }

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return Collection<int, T>
     */
    private function within(Builder $query, Coordinate $point, int $metres): Collection
    {
        return SuggestionBox::around($query, $point, $metres)
            ->get()
            ->map(fn (Model $row): array => [$row, Haversine::between($point, new Coordinate((float) $row->lat, (float) $row->lng))->metres])
            ->filter(fn (array $pair): bool => $pair[1] <= $metres)
            ->sortBy(fn (array $pair): float => $pair[1])
            ->map(fn (array $pair): Model => $pair[0])
            ->values();
    }

    private static function cleanLabel(?string $label): ?string
    {
        $label = $label === null ? null : trim($label);

        return $label === null || $label === '' ? null : mb_substr($label, 0, 255);
    }

    public static function mergeMetres(): int
    {
        return (int) config('rafeeq.places.merge_metres');
    }

    private static function sharedMetres(): int
    {
        return (int) config('rafeeq.places.shared_pin_metres');
    }

    private static function sharedUsers(): int
    {
        return max(2, (int) config('rafeeq.places.shared_pin_users'));
    }
}
