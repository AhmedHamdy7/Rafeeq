<?php

namespace App\Http\Requests\Safety;

use Illuminate\Foundation\Http\FormRequest;

final class SetAutoShareRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}
