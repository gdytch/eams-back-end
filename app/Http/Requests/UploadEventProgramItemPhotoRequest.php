<?php

namespace App\Http\Requests;

use App\Rules\ValidImageUpload;
use Illuminate\Foundation\Http\FormRequest;

class UploadEventProgramItemPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $program = $this->route('event')?->program;
        if (! $program) {
            // Let the controller handle 404 for missing program
            return true;
        }

        return $this->user()->can('update', $program);
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
