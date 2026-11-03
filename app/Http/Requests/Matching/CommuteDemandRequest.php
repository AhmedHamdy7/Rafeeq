<?php

namespace App\Http\Requests\Matching;

use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Geo\Support\Haversine;
use App\Domains\Identity\Enums\Gender;
use App\Domains\Matching\Support\PlaceLabels;
use App\Domains\Matching\Support\SearchCriteria;
use App\Domains\Shared\ValueObjects\Coordinate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CommuteDemandRequest extends FormRequest
{
    public function rules(): array
    {
        return (new SearchCommutesRequest)->rules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $origin = $this->input('origin');
            $destination = $this->input('destination');

            $samePlace = ($origin['placeId'] ?? null) !== null && ($origin['placeId'] ?? null) === ($destination['placeId'] ?? null);
            $distance = Haversine::between(
                new Coordinate((float) $origin['lat'], (float) $origin['lng']),
                new Coordinate((float) $destination['lat'], (float) $destination['lng']),
            );

            if ($samePlace || $distance->metres < (int) config('rafeeq.matching.demand_min_trip_metres')) {
                $validator->errors()->add('destination', __('validation.custom.demand.same_place'));
            }

            if ($this->boolean('womenOnly') && $this->user()?->gender !== Gender::Woman) {
                $validator->errors()->add('womenOnly', __('validation.custom.demand.women_only'));
            }
        });
    }

    public function criteria(): SearchCriteria
    {
        $input = $this->safe()->except('paused');

        if (filter_var($input['womenOnly'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $input['audiencePreference'] = CommuteAudience::WomenOnly->value;
        }

        return SearchCriteria::fromArray($input);
    }

    public function places(): PlaceLabels
    {
        return PlaceLabels::fromInput($this->validated());
    }
}
