<?php

namespace App\Services;

use App\Enums\FormFieldType;
use App\Enums\FormStatus;
use App\Models\Event;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldOption;
use Illuminate\Validation\ValidationException;

/**
 * Applies an edited form definition while keeping field and option ids stable.
 * Removing an answered field or option archives it; an unanswered one is deleted.
 */
class FormAuthoring
{
    /**
     * Replace the form's definition with $data. Returns true when the change tightens the rules
     * (a new required field, a field made required, or the form made required).
     *
     * @param  array<string, mixed>  $data
     */
    public function sync(Form $form, array $data): bool
    {
        $tightens = ($data['is_required'] ?? $form->is_required) && ! $form->is_required;
        $form->fill([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'is_required' => $data['is_required'] ?? $form->is_required,
        ])->save();

        $existing = $form->fields()->active()->get()->keyBy('id');
        $kept = [];

        foreach (array_values($data['fields']) as $i => $input) {
            $type = FormFieldType::from($input['type']);
            $field = isset($input['id']) ? $existing->get($input['id']) : new FormField(['form_id' => $form->id]);
            $required = (bool) ($input['is_required'] ?? $field?->is_required ?? false);
            if ($field === null) {
                throw ValidationException::withMessages(["fields.$i.id" => 'This field no longer exists. Reload the form.']);
            }

            if ($field->exists && $field->type !== $type && $field->answers()->exists()) {
                throw ValidationException::withMessages(["fields.$i.type" => 'The type of a field with answers cannot be changed.']);
            }
            $tightens = $tightens || ($required && ! ($field->exists && $field->is_required));

            $field->fill([
                'type' => $type,
                'label' => $input['label'],
                'help_text' => $input['help_text'] ?? null,
                'is_required' => $required,
                'sort_order' => $i,
            ])->save();
            $kept[] = $field->id;

            $this->syncOptions($field, $type->hasOptions() ? array_values($input['options'] ?? []) : []);
        }

        foreach ($existing->except($kept) as $field) {
            $field->answers()->exists() ? $field->forceFill(['archived_at' => now()])->save() : $field->delete();
        }

        return $tightens;
    }

    /**
     * New unlinked draft in the same organization, with the source's current fields and options.
     */
    public function copy(Form $source, Event $event): Form
    {
        $copy = $event->forms()->create([
            'organization_id' => $event->organization_id,
            'title' => $source->title,
            'description' => $source->description,
            'is_required' => $source->is_required,
            'status' => FormStatus::Draft,
            'sort_order' => $this->nextSortOrder($event),
        ]);

        foreach ($source->fields()->active()->with(['options' => fn ($query) => $query->active()])->get() as $field) {
            $newField = $copy->fields()->create($field->only(['type', 'label', 'help_text', 'is_required', 'sort_order']));
            foreach ($field->options as $option) {
                $newField->options()->create($option->only(['label', 'sort_order']));
            }
        }

        return $copy;
    }

    public function nextSortOrder(Event $event): int
    {
        // Serializes concurrent creates and reorders of the event's forms.
        Event::withoutGlobalScopes()->whereKey($event->id)->lockForUpdate()->first();

        return ($event->forms()->reorder()->max('sort_order') ?? -1) + 1;
    }

    /** @param  array<int, array<string, mixed>>  $inputs */
    private function syncOptions(FormField $field, array $inputs): void
    {
        $existing = $field->options()->active()->get()->keyBy('id');
        $kept = [];

        foreach ($inputs as $i => $input) {
            $option = isset($input['id']) ? $existing->get($input['id']) : new FormFieldOption(['form_field_id' => $field->id]);
            if ($option === null) {
                throw ValidationException::withMessages(['fields' => 'An option no longer exists. Reload the form.']);
            }
            $option->fill(['label' => $input['label'], 'sort_order' => $i])->save();
            $kept[] = $option->id;
        }

        foreach ($existing->except($kept) as $option) {
            $option->answers()->exists() ? $option->forceFill(['archived_at' => now()])->save() : $option->delete();
        }
    }
}
