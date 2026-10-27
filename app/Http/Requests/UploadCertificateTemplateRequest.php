<?php

namespace App\Http\Requests;

use App\Rules\ValidImageUpload;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UploadCertificateTemplateRequest extends FormRequest
{
    public function authorize(): Response|bool
    {
        return Gate::forUser($this->user())->inspect('update', $this->route('certificate'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'template' => ['required', 'file', new ValidImageUpload(['image/png', 'image/jpeg'], (int) config('certificates.template_max_bytes'))],
        ];
    }
}
