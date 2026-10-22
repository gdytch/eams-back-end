<?php

namespace App\Http\Requests;

use App\Models\Church;
use App\Models\Mission;
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
                Rule::exists('organizations', 'id')->when(! $this->user()->isSuperAdmin(), fn ($rule) => $rule->where('id', $this->user()->organization_id)),
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
            ],
            'address' => ['required', 'string', 'max:255'],
            'confirm_same_name' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if (! $validator->errors()->hasAny(['union_id', 'mission_id'])
                && ! Mission::withoutGlobalScopes()->whereKey($this->integer('mission_id'))->where('union_id', $this->integer('union_id'))->exists()) {
                $validator->errors()->add('mission_id', 'The mission does not belong to the union.');
            }
        }];
    }
}
