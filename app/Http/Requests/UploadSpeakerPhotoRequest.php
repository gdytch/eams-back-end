<?php

namespace App\Http\Requests;

use App\Rules\ValidImageUpload;
use Illuminate\Foundation\Http\FormRequest;

class UploadSpeakerPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('event'));
    }

    public function rules(): array
    {
        return ['photo' => ['required', new ValidImageUpload(['image/png'])]];
    }
}
