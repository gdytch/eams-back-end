<?php

namespace App\Http\Requests;

use App\Enums\OrganizationLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAttendeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('attendee'));
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
