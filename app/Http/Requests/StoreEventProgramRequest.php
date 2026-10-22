<?php

namespace App\Http\Requests;

use App\Models\EventProgram;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreEventProgramRequest extends FormRequest
{
    public function authorize(): Response|bool
    {
        return Gate::forUser($this->user())->inspect('create', [EventProgram::class, $this->route('event')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['title' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:10000']];
    }
}
