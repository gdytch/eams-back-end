<?php

namespace App\Http\Requests;

use App\Models\Mission;
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
            'address' => ['sometimes', 'required', 'string', 'max:255'],
            'confirm_same_name' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($validator->errors()->hasAny(['union_id', 'mission_id'])) {
                return;
            }

            $church = $this->route('church');
            $unionId = $this->integer('union_id', $church->union_id);
            $missionId = $this->integer('mission_id', $church->mission_id);

            if (! Mission::withoutGlobalScopes()->whereKey($missionId)->where('union_id', $unionId)->exists()) {
                $validator->errors()->add('mission_id', 'The mission does not belong to the union.');
            }
        }];
    }
}
