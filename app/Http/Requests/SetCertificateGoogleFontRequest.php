<?php

namespace App\Http\Requests;

use App\Services\GoogleFontService;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SetCertificateGoogleFontRequest extends FormRequest
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
            'url' => ['required', 'string', 'max:2048', function (string $attribute, mixed $value, Closure $fail) {
                if (app(GoogleFontService::class)->familyFromLink($value) === null) {
                    $fail('Enter a Google Fonts link (fonts.google.com or fonts.googleapis.com) that names a font family.');
                }
            }],
        ];
    }

    public function family(): string
    {
        return app(GoogleFontService::class)->familyFromLink($this->validated('url'));
    }
}
