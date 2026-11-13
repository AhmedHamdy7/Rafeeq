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
            /*
             * Part of a place name, district or city, in Arabic or English. With `q`, every matching
             * place is found wherever it is: names that start with it come first, then names that
             * contain it, then places that only match by area. Inside each group the nearest comes
             * first when `lat` / `lng` are sent, then the most used.
             */
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],

            /*
             * The point to measure from, usually where the person is. Both must be sent together.
             * Without `q`: the places near this point, nearest first, within `radiusMetres`.
             * With `q`: only orders the results and adds `distanceMetres` to each; places far
             * away are still returned unless `radiusMetres` is sent.
             *
             * `nullable`, NOT `sometimes`: `sometimes` skips a field's whole rule
             * set when the field is absent, so `required_with` on the missing half
             * of a pair would never run — sending only a latitude would be
             * accepted and silently ignored.
             */
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],

            /*
             * How far from that point to look. Without `q` it defaults to 5km. With `q` it applies
             * only when sent, and the matches inside it are then ordered by distance alone.
             */
            'radiusMetres' => ['sometimes', 'integer', 'min:100', 'max:50000'],

            // How many places to return, best first. Defaults to 20.
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ];
    }
}
