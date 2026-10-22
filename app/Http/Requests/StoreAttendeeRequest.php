<?php

namespace App\Http\Requests;

use App\Enums\OrganizationLevel;
use App\Http\Requests\Concerns\ValidatesTerritory;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreAttendeeRequest extends FormRequest
{
    use ValidatesTerritory;

    private ?Event $event = null;

    private ?User $linkedUser = null;

    private bool $linkedUserLoaded = false;

    public function authorize(): Response|bool
    {
        // Admin wizard: org_admin and super_admin only.
        if (! ($this->user()->isSuperAdmin() || $this->user()->isOrgAdmin()) || ! $this->user()->can('create', Attendee::class)) {
            return false;
        }

        // A missing event fails validation (422); an existing one needs permission to register people in it.
        $event = $this->event();

        return $event === null ? true : Gate::forUser($this->user())->inspect('create', [Attendee::class, $event]);
    }

    /**
     * The event the new Attendee registers in. The organization always follows the event, never client input.
     */
    public function event(): ?Event
    {
        return $this->event ??= Event::withoutGlobalScopes()->find($this->integer('event_id'));
    }

    /**
     * The User to link the new Attendee to: an attendee-role User with a verified email. An unverified owner
     * is not linked (the verified-email rule links later), so a squatted account cannot take the Attendee.
     */
    public function linkedUser(): ?User
    {
        $owner = $this->emailOwner();

        return $owner?->isAttendee() && $owner->hasVerifiedEmail() ? $owner : null;
    }

    private function emailOwner(): ?User
    {
        if (! $this->linkedUserLoaded) {
            $email = $this->input('email_address');
            $this->linkedUser = blank($email) ? null : User::withoutGlobalScopes()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();
            $this->linkedUserLoaded = true;
        }

        return $this->linkedUser;
    }

    protected function currentTerritory(): ?array
    {
        // Territory is its own wizard step: validated only when sent with Basic info.
        return $this->has('organization_level') ? $this->only(['organization_level', 'union_id', 'mission_id', 'church_id']) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->event()?->organization_id;
        $eventId = $this->integer('event_id');

        return [
            'event_id' => ['required', 'integer', Rule::exists('events', 'id')],
            'organization_level' => ['sometimes', 'required', Rule::enum(OrganizationLevel::class)],
            'union_id' => ['required_with:organization_level', Rule::exists('unions', 'id')->where('organization_id', $organizationId)],
            'mission_id' => ['nullable', Rule::exists('missions', 'id')->where('organization_id', $organizationId)],
            'church_id' => ['nullable', Rule::exists('churches', 'id')->where('organization_id', $organizationId)],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'mobile_no' => ['nullable', 'string', 'max:20'],
            'email_address' => [
                'nullable',
                'email',
                'max:255',
                // A linked Attendee holds its user's email and is returned, not rejected.
                Rule::unique('attendees', 'email_address')->where('event_id', $eventId)->whereNull('user_id'),
                function (string $attribute, mixed $value, Closure $fail) {
                    if ($this->emailOwner()?->isAttendee() === false) {
                        $fail('This email address is already in use by another attendee or account.');
                    }
                },
            ],
            'remarks' => ['nullable', 'string'],
            'confirm' => ['sometimes', 'boolean'],
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
