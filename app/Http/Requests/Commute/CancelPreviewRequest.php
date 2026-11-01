<?php

namespace App\Http\Requests\Commute;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Which cancelling act to price (C-9).
 */
final class CancelPreviewRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /*
             * `pause` (this commute's upcoming confirmed seats), `delete` (the same), or
             * `replace_vehicle` (every commute of hers — one car serves them all).
             */
            'action' => ['required', 'string', 'in:pause,delete,replace_vehicle'],
        ];
    }
}
