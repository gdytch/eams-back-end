<?php

namespace App\Http\Requests;

use App\Models\Union;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUnionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Union::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organization_id' => [
                Rule::requiredIf(fn () => $this->user()->isSuperAdmin()),
                'nullable',
                'exists:organizations,id',
            ],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
        ];
    }
}
