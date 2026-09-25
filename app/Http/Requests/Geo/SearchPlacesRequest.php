<?php

namespace App\Http\Requests\Geo;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Searching the shared place catalogue.
 *
 * ---
 *
 * Maintainer note: the proximity filter is a bounding box on the duplicated
 * lat/lng columns, then a real distance measurement over the shortlist. A
 * distance function in the WHERE clause cannot use an index and would read the
 * table.
 */
final class SearchPlacesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Part of a name or district, in Arabic or English.
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],

            /*
             * Search near a point on the map. Both must be sent together.
             *
             * `nullable`, NOT `sometimes`: `sometimes` skips a field's whole rule
             * set when the field is absent, so `required_with` on the missing half
             * of a pair would never run — sending only a latitude would be
             * accepted and silently ignored.
             */
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],

            // How far from that point to look. Defaults to 5km.
            'radiusMetres' => ['sometimes', 'integer', 'min:100', 'max:50000'],
        ];
    }
}
