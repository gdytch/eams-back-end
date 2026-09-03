<?php

namespace App\Http\Requests;

use App\Rules\ValidImageUpload;
use Illuminate\Foundation\Http\FormRequest;

class UploadAttendeePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('attendee'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'photo' => ['required', new ValidImageUpload],
        ];
    }
}
