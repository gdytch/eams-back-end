<?php

namespace Database\Seeders;

use App\Enums\FormFieldType;
use App\Enums\FormStatus;
use App\Models\Event;
use App\Models\Form;
use Illuminate\Database\Seeder;

class FormSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Event::withoutGlobalScopes()->get() as $event) {
            if (Form::withoutGlobalScopes()->where('event_id', $event->id)->exists()) {
                continue;
            }

            $this->makeForm($event, 0, true, 'Shirt Size & Diet Info', 'Help us prepare your event shirt and meals.', [
                ['type' => FormFieldType::SingleChoice, 'label' => 'Shirt size', 'required' => true, 'options' => ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL']],
                ['type' => FormFieldType::MultipleChoice, 'label' => 'Dietary preferences', 'required' => true, 'options' => ['No restriction', 'Vegetarian', 'Vegan', 'No pork', 'Gluten-free']],
                ['type' => FormFieldType::ShortText, 'label' => 'Food allergies', 'help' => 'Leave blank if none.'],
            ]);

            $this->makeForm($event, 1, true, 'Emergency Contact', 'Who should we call in an emergency?', [
                ['type' => FormFieldType::ShortText, 'label' => 'Contact name', 'required' => true],
                ['type' => FormFieldType::ShortText, 'label' => 'Contact mobile number', 'required' => true],
                ['type' => FormFieldType::SingleChoice, 'label' => 'Relationship', 'options' => ['Spouse', 'Parent', 'Sibling', 'Child', 'Friend']],
            ]);

            $this->makeForm($event, 2, false, 'Additional Info', 'Optional details to help us plan.', [
                ['type' => FormFieldType::Number, 'label' => 'Number of companions'],
                ['type' => FormFieldType::LongText, 'label' => 'Special needs or requests'],
            ]);
        }
    }

    /**
     * @param  array<int, array{type: FormFieldType, label: string, required?: bool, help?: string, options?: array<int, string>}>  $fields
     */
    private function makeForm(Event $event, int $order, bool $required, string $title, string $description, array $fields): void
    {
        $form = Form::query()->create([
            'organization_id' => $event->organization_id,
            'event_id' => $event->id,
            'title' => $title,
            'description' => $description,
            'is_required' => $required,
            'status' => FormStatus::Published,
            'sort_order' => $order,
        ]);

        foreach ($fields as $index => $data) {
            $field = $form->fields()->create([
                'type' => $data['type'],
                'label' => $data['label'],
                'help_text' => $data['help'] ?? null,
                'is_required' => $data['required'] ?? false,
                'sort_order' => $index,
            ]);

            foreach ($data['options'] ?? [] as $optionIndex => $label) {
                $field->options()->create(['label' => $label, 'sort_order' => $optionIndex]);
            }
        }
    }
}
