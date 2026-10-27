<?php

namespace App\Http\Requests;

use App\Enums\CertificateFont;
use App\Enums\CertificateOrientation;
use App\Enums\CertificatePageSize;
use App\Models\Certificate;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveCertificateRequest extends FormRequest
{
    public function authorize(): Response|bool
    {
        $gate = Gate::forUser($this->user());

        return $this->isMethod('POST')
            ? $gate->inspect('create', [Certificate::class, $this->route('event')])
            : $gate->inspect('update', $this->route('certificate'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title' => [$presence, 'string', 'max:255'],
            // Fractions of the page: x and y of the name's centre, size as a share of page width.
            'name_x' => ['sometimes', 'numeric', 'between:0,1'],
            'name_y' => ['sometimes', 'numeric', 'between:0,1'],
            'name_font_size' => ['sometimes', 'numeric', 'between:0.005,0.25'],
            'font' => ['sometimes', Rule::enum(CertificateFont::class), function (string $attribute, mixed $value, Closure $fail) {
                if ($value === CertificateFont::Google->value && ! $this->route('certificate')?->google_font_path) {
                    $fail('Add a Google Fonts link before choosing a Google font.');
                }
            }],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'page_size' => ['sometimes', Rule::enum(CertificatePageSize::class)],
            'orientation' => ['sometimes', Rule::enum(CertificateOrientation::class)],
        ];
    }
}
