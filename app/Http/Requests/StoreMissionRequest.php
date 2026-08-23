<?php

namespace App\Http\Requests;

use App\Models\Mission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Mission::class);
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
            'union_id' => [
                'required',
                Rule::exists('unions', 'id')->where('organization_id', $this->resolvedOrganizationId()),
            ],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * The organization the new record will belong to: explicit input for Super Admins, else the actor's own organization.
     */
    protected function resolvedOrganizationId(): ?int
    {
        return $this->user()->isSuperAdmin()
            ? $this->integer('organization_id')
            : $this->user()->organization_id;
    }
}
