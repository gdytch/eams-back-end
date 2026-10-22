<?php

namespace App\Http\Requests;

use App\Rules\ValidImageUpload;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UploadAttendeePhotoRequest extends FormRequest
{
    public function authorize(): Response|bool
    {
        return Gate::forUser($this->user())->inspect('update', $this->route('attendee'));
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
