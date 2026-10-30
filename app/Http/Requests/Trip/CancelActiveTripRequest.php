<?php

namespace App\Http\Requests\Trip;

use Illuminate\Foundation\Http\FormRequest;

final class CancelActiveTripRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /*
             * 🔴 REQUIRED here, unlike "cancel today" where it is optional, and the difference is
             * not a stylistic one. v5 asks for a reason on the driver's mid-journey endings
             * ("driver End Passenger Ride Early requires reason") and this is the heaviest of them:
             * it stops a car with people in it, it goes on her reliability record at the heaviest
             * weight in the ledger, and she may appeal it. An appeal reviewed against a blank
             * reason is a reviewer guessing.
             *
             * It is NOT sent to the passengers — see the Action — so what she writes here is for
             * staff and for her own appeal, not a note she has to justify to the car.
             */
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
