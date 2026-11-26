<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Geo\Actions\SuggestPlacesAction;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Geo\SuggestPlacesRequest;
use App\Http\Resources\PlaceSuggestionResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class PlaceSuggestionController extends Controller
{
    public function index(SuggestPlacesRequest $request, SuggestPlacesAction $action): JsonResponse
    {
        $near = $request->filled(['lat', 'lng'])
            ? new Coordinate((float) $request->validated('lat'), (float) $request->validated('lng'))
            : null;

        $q = $request->validated('q');

        $found = $action->execute(
            near: $near,
            term: is_string($q) ? $q : null,
            kind: $request->validated('kind'),
            limit: (int) ($request->validated('limit') ?? config('rafeeq.places.default_limit')),
        );

        $items = [];

        foreach ($found as $item) {
            $items[] = PlaceSuggestionResource::make($item['row'], $item['distance']);
        }

        return ApiResponse::success($items);
    }
}
