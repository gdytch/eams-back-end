<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateSpeakerRequest extends FormRequest
{
    public function authorize(): Response|bool
    {
        return Gate::forUser($this->user())->inspect('update', $this->route('event'));
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'designation' => ['required', 'string', 'max:255'],
            'organization' => ['required', 'string', 'max:255'],
            'bio' => ['required', 'string', 'max:2000'],
        ];
    }
}
