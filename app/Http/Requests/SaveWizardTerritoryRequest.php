<?php

namespace App\Http\Requests;

use App\Enums\EventStatus;
use App\Enums\OrganizationLevel;
use App\Http\Requests\Concerns\ValidatesTerritory;
use App\Models\Event;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveWizardTerritoryRequest extends FormRequest
{
    use ValidatesTerritory;

    /**
     * Checked before validation so an archived event answers 403, not 422.
     */
    public function authorize(): Response|bool
    {
        $event = Event::withoutGlobalScopes()->where('invite_token', $this->route('eventInviteToken'))->firstOrFail();
        abort_unless($event->status === EventStatus::Published, 404);

        return Gate::forUser($this->user())->inspect('register', $event);
    }

    protected function currentTerritory(): array
    {
        return $this->only(['organization_level', 'union_id', 'mission_id', 'church_id']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = Event::withoutGlobalScopes()->where('invite_token', $this->route('eventInviteToken'))->value('organization_id');

        return [
            'organization_level' => ['required', Rule::enum(OrganizationLevel::class)],
            'union_id' => ['required', Rule::exists('unions', 'id')->where('organization_id', $organizationId)],
            'mission_id' => ['nullable', Rule::exists('missions', 'id')->where('organization_id', $organizationId)],
            'church_id' => ['nullable', Rule::exists('churches', 'id')->where('organization_id', $organizationId)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['union_id' => 'union', 'mission_id' => 'mission', 'church_id' => 'church'];
    }
}
