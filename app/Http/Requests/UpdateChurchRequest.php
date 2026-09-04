<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChurchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('church'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->route('church')->organization_id;

        return [
            'union_id' => [
                'sometimes',
                Rule::exists('unions', 'id')->where('organization_id', $organizationId),
            ],
            'mission_id' => [
                'sometimes',
                Rule::exists('missions', 'id')->where('organization_id', $organizationId),
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
        ];
    }
}
