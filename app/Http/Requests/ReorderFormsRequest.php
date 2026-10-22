<?php

namespace App\Http\Requests;

use App\Models\Form;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ReorderFormsRequest extends FormRequest
{
    public function authorize(): Response|bool
    {
        return Gate::forUser($this->user())->inspect('create', [Form::class, $this->route('event')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'form_ids' => ['required', 'array', 'min:1'],
            'form_ids.*' => ['integer', 'distinct'],
        ];
    }
}
