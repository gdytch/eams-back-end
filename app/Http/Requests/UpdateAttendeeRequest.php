<?php

namespace App\Http\Requests;

use App\Enums\OrganizationLevel;
use App\Http\Requests\Concerns\ValidatesTerritory;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateAttendeeRequest extends FormRequest
{
    use ValidatesTerritory;

    public function authorize(): Response|bool
    {
        return Gate::forUser($this->user())->inspect('update', $this->route('attendee'));
    }

    protected function currentTerritory(): ?array
    {
        if (! $this->hasAny(['organization_level', 'union_id', 'mission_id', 'church_id'])) {
            return null;
        }

        $attendee = $this->route('attendee');

        return [
            'organization_level' => $this->input('organization_level', $attendee->organization_level?->value),
            'union_id' => $this->input('union_id', $attendee->union_id),
            'mission_id' => $this->input('mission_id', $attendee->mission_id),
            'church_id' => $this->input('church_id', $attendee->church_id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $attendee = $this->route('attendee');
        $organizationId = $attendee->organization_id;
        $isStaff = ! $this->user()->isAttendee();

        $usersEmailRule = Rule::unique('users', 'email');
        if ($attendee->user_id !== null) {
            $usersEmailRule->ignore($attendee->user_id);
        }

        return [
            'organization_level' => ['nullable', Rule::enum(OrganizationLevel::class)],
            'union_id' => ['nullable', Rule::exists('unions', 'id')->where('organization_id', $organizationId)],
            'mission_id' => ['nullable', Rule::exists('missions', 'id')->where('organization_id', $organizationId)],
            'church_id' => ['nullable', Rule::exists('churches', 'id')->where('organization_id', $organizationId)],
            'first_name' => $isStaff ? ['sometimes', 'string', 'max:100'] : ['prohibited'],
            'middle_name' => $isStaff ? ['nullable', 'string', 'max:100'] : ['prohibited'],
            'last_name' => $isStaff ? ['sometimes', 'string', 'max:100'] : ['prohibited'],
            'mobile_no' => ['nullable', 'string', 'max:20'],
            'email_address' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('attendees', 'email_address')->ignore($attendee->id),
                $usersEmailRule,
            ],
            'remarks' => ['nullable', 'string'],
            'override_duplicate' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email_address.unique' => 'This email address is already in use by another attendee or account.',
        ];
    }
}
