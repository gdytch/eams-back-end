<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ReorderEventProgramItemsRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:event_program_items,id'],
            'items.*.order' => ['required', 'integer', 'min:0'],
        ];
    }
}
