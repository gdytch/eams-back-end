<?php

namespace App\Http\Requests;

use App\Rules\ValidImageUpload;
use Illuminate\Foundation\Http\FormRequest;

class UploadEventBannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('event'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'banner' => ['required', new ValidImageUpload],
        ];
    }
}
