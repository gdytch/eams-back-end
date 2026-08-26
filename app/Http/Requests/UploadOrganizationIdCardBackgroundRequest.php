<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadOrganizationIdCardBackgroundRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('organization');

        return $this->user()->isSuperAdmin()
            || ($this->user()->isOrgAdmin() && $this->user()->organization_id === $organization->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'background' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:5120'],
        ];
    }
}
