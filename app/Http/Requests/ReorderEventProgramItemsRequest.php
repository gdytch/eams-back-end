<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReorderEventProgramItemsRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:event_program_items,id'],
            'items.*.order' => ['required', 'integer', 'min:0'],
        ];
    }
}
