<?php

namespace App\Http\Requests;

use App\Models\EventProgram;
use Illuminate\Foundation\Http\FormRequest;

class StoreEventProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [EventProgram::class, $this->route('event')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
