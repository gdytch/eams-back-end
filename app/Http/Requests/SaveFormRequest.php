<?php

namespace App\Http\Requests;

use App\Enums\FormFieldType;
use App\Models\Form;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveFormRequest extends FormRequest
{
    public function authorize(): Response|bool
    {
        $gate = Gate::forUser($this->user());

        return $this->isMethod('POST')
            ? $gate->inspect('create', [Form::class, $this->route('event')])
            : $gate->inspect('update', $this->route('form'));
    }

    /**
     * Fields and options carry their stable id on update; ids missing from the list are removed or archived.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $ids = $this->isMethod('POST') ? ['prohibited'] : ['nullable', 'integer', 'distinct'];

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_required' => ['sometimes', 'boolean'],
            'fields' => ['present', 'array', 'max:100'],
            'fields.*.id' => $ids,
            'fields.*.type' => ['required', Rule::enum(FormFieldType::class)],
            'fields.*.label' => ['required', 'string', 'max:255'],
            'fields.*.help_text' => ['nullable', 'string', 'max:1000'],
            'fields.*.is_required' => ['sometimes', 'boolean'],
            'fields.*.options' => ['sometimes', 'nullable', 'array', 'max:100'],
            'fields.*.options.*.id' => $ids,
            'fields.*.options.*.label' => ['required', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $form = $this->route('form');
            $known = $form instanceof Form
                ? $form->fields()->active()->with(['options' => fn ($query) => $query->active()])->get()->keyBy('id')
                : collect();

            foreach ($this->input('fields', []) as $i => $field) {
                $type = FormFieldType::tryFrom($field['type'] ?? '');
                $options = $field['options'] ?? [];

                if ($type?->hasOptions() && $options === []) {
                    $validator->errors()->add("fields.$i.options", 'A choice field needs at least one option.');
                }
                if (isset($field['id']) && ! $known->has($field['id'])) {
                    $validator->errors()->add("fields.$i.id", 'This field does not belong to the form.');

                    continue;
                }
                $knownOptions = isset($field['id']) ? $known[$field['id']]->options->keyBy('id') : collect();
                foreach ($options as $j => $option) {
                    if (isset($option['id']) && ! $knownOptions->has($option['id'])) {
                        $validator->errors()->add("fields.$i.options.$j.id", 'This option does not belong to the field.');
                    }
                }
            }
        }];
    }
}
