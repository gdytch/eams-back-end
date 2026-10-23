<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FormAnswersIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }
}
