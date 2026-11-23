<?php

namespace App\Http\Requests\Matching;

use App\Domains\Commute\Enums\CommuteAudience;
use App\Domains\Commute\Enums\CommuteDirection;
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
        $rules = (new SearchCommutesRequest)->rules();

        unset($rules['dateFrom'], $rules['dateTo'], $rules['minSeatsOpen']);
        $rules['daysMask'] = ['required', 'integer', 'min:1', 'max:127'];

        /*
         * B6, D4: on a Going demand, `{"leaveAt": "17:00"}` also saves the ride back: a Return
         * demand with the places swapped and the same days, preferences and rules.
         */
        $rules['returnDemand'] = ['sometimes', 'array'];
        $rules['returnDemand.leaveAt'] = ['required_with:returnDemand', 'string', 'date_format:H:i:s,H:i'];

        return $rules;
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

            if ($this->has('returnDemand') && CommuteDirection::fromInput($this->input('direction')) === CommuteDirection::Return) {
                $validator->errors()->add('returnDemand', __('validation.custom.demand.return_of_return'));
            }

            if ($this->boolean('womenOnly') && $this->user()?->gender !== Gender::Woman) {
                $validator->errors()->add('womenOnly', __('validation.custom.demand.women_only'));
            }
        });
    }

    public function wantsReturnDemand(): bool
    {
        return $this->has('returnDemand.leaveAt');
    }

    /**
     * The ride back: the places swapped, Return, leaving at `returnDemand.leaveAt`.
     */
    public function returnCriteria(): SearchCriteria
    {
        $input = $this->swapped($this->safe()->except(['paused', 'returnDemand']));
        $input['direction'] = CommuteDirection::Return->value;
        $input['leaveAt'] = (string) $this->input('returnDemand.leaveAt');

        if (filter_var($input['womenOnly'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $input['audiencePreference'] = CommuteAudience::WomenOnly->value;
        }

        return SearchCommutesRequest::googleAreas(SearchCriteria::fromArray($input), $input['origin'] ?? null, $input['destination'] ?? null);
    }

    public function returnPlaces(): PlaceLabels
    {
        return PlaceLabels::fromInput($this->swapped($this->validated()));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function swapped(array $input): array
    {
        [$input['origin'], $input['destination']] = [$input['destination'] ?? null, $input['origin'] ?? null];

        return $input;
    }

    public function criteria(): SearchCriteria
    {
        $input = $this->safe()->except(['paused', 'returnDemand']);

        if (filter_var($input['womenOnly'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $input['audiencePreference'] = CommuteAudience::WomenOnly->value;
        }

        return SearchCommutesRequest::googleAreas(SearchCriteria::fromArray($input), $input['origin'] ?? null, $input['destination'] ?? null);
    }

    public function places(): PlaceLabels
    {
        return PlaceLabels::fromInput($this->validated());
    }
}
