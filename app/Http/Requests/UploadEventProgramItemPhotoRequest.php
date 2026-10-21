<?php

namespace App\Http\Requests;

use App\Rules\ValidImageUpload;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UploadEventProgramItemPhotoRequest extends FormRequest
{
    public function authorize(): Response|bool
    {
        $program = $this->route('event')?->program;
        if (! $program) {
            // Let the controller handle 404 for missing program
            return true;
        }

        return Gate::forUser($this->user())->inspect('update', $program);
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
