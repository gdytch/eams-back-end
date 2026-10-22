<?php

namespace App\Http\Requests;

use App\Models\Breakout;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveBreakoutRequest extends FormRequest
{
    public function authorize(): Response|bool
    {
        $gate = Gate::forUser($this->user());

        return $this->isMethod('POST')
            ? $gate->inspect('create', [Breakout::class, $this->route('event')])
            : $gate->inspect('update', $this->route('breakout'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'date' => ['required', 'date_format:Y-m-d'],
            // Same-day times only: an end that is not after the start also rejects crossing midnight.
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'room' => ['required', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'topics' => ['required', 'array', 'min:1'],
            'topics.*' => ['required', 'string', 'max:255'],
            'speaker_ids' => ['present', 'array'],
            'speaker_ids.*' => ['integer', 'distinct', Rule::exists('speakers', 'id')->where('event_id', $this->route('event')->id)],
        ];
    }
}
