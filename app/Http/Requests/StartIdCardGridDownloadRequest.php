<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartIdCardGridDownloadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isSuperAdmin() || $this->user()->isOrgAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'registration_ids' => ['sometimes', 'array'],
            'registration_ids.*' => ['integer'],
        ];
    }
}
