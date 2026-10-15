<?php

namespace App\Http\Resources;

use App\Domains\Identity\Models\UserComfortPreference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One mode's comfort answers.
 *
 * @mixin UserComfortPreference
 */
final class ComfortPreferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'mode' => $this->mode->value,
            // `yes` · `no` · `either`. `either` is "no preference" and the default — do not render
            // it as "no".
            'smoking' => $this->smoking->value,
            'music' => $this->music->value,
            'conversation' => $this->conversation->value,
            'airConditioning' => $this->air_conditioning->value,
        ];
    }
}
