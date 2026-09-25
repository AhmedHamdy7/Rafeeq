<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Geo\Actions\SearchPlacesAction;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Shared\ValueObjects\Distance;
use App\Http\Controllers\Controller;
use App\Http\Requests\Geo\SearchPlacesRequest;
use App\Http\Resources\PlaceResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class PlaceController extends Controller
{
    /**
     * GET /v1/places — the shared catalogue of meeting points, searchable by
     * name or by proximity (Chapter 4 §3).
     *
     * Read-only. The catalogue is curated rather than crowd-filled: letting every
     * driver add a global place would turn it into thousands of near-duplicate
     * pins with no canonical name, and the whole value of a place is that two
     * people mean the same thing by it. A driver who needs a point that is not
     * listed pins coordinates directly on their commute instead, and admin
     * curation arrives with the dashboard.
     */
    public function index(SearchPlacesRequest $request, SearchPlacesAction $action): JsonResponse
    {
        $near = $request->filled(['lat', 'lng'])
            ? new Coordinate((float) $request->validated('lat'), (float) $request->validated('lng'))
            : null;

        $places = $action->execute(
            term: $request->validated('q'),
            near: $near,
            within: $request->filled('radiusMetres')
                ? Distance::fromMetres((int) $request->validated('radiusMetres'))
                : null,
        );

        return ApiResponse::success(PlaceResource::collection($places));
    }
}
