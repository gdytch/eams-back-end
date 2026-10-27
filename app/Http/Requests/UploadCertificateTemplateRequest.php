<?php

namespace App\Http\Requests;

use App\Models\Certificate;
use App\Rules\ValidImageUpload;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

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
            // Sent with the image when the Template came from a PDF.
            'template_pdf' => ['nullable', 'file', new ValidImageUpload(['application/pdf'], (int) config('certificates.template_pdf_max_bytes'))],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $certificate = $this->route('certificate');
            $file = $this->file('template');
            if (! $certificate instanceof Certificate || ! $file || $validator->errors()->has('template')) {
                return;
            }

            $size = @getimagesize($file->getRealPath());
            if ($size && $message = $certificate->templateTooLargeMessage($size[0], $size[1])) {
                $validator->errors()->add('template', $message);
            }
        }];
    }
}
