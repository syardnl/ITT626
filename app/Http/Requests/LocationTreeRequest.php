<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LocationTreeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
