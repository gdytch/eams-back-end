<?php

namespace App\Http\Requests;

use App\Models\Church;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChurchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Church::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->isSuperAdmin()
            ? $this->integer('organization_id')
            : $this->user()->organization_id;

        return [
            'organization_id' => [
                Rule::requiredIf(fn () => $this->user()->isSuperAdmin()),
                'nullable',
                'exists:organizations,id',
            ],
            'union_id' => [
                'required',
                Rule::exists('unions', 'id')->where('organization_id', $organizationId),
            ],
            'mission_id' => [
                'required',
                Rule::exists('missions', 'id')->where('organization_id', $organizationId),
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('churches', 'name')->where('mission_id', $this->integer('mission_id')),
            ],
            'address' => ['nullable', 'string', 'max:255'],
        ];
    }
}
