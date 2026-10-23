<?php

namespace Tests\Feature;

use App\Enums\FormFieldType;
use App\Exports\FormAnswersSheetExport;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\Organization;
use App\Services\FormAnswersTable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class FormAnswersTableTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Event $event;

    private Form $form;

    protected function setUp(): void
    {
        parent::setUp();
        $this->event = Event::factory()->for(Organization::factory()->create())->create();
        $this->form = Form::factory()->for($this->event)->published()->create();
    }

    private function field(string $label, FormFieldType $type, array $extra = []): FormField
    {
        return $this->form->fields()->create(['type' => $type, 'label' => $label] + $extra);
    }

    private function submission(array $attendee = []): FormSubmission
    {
        $attendee = Attendee::factory()->for($this->event)->create($attendee);

        return FormSubmission::create(['form_id' => $this->form->id, 'attendee_id' => $attendee->id]);
    }

    private function table(): FormAnswersTable
    {
        return new FormAnswersTable($this->form);
    }

    public function test_columns_put_archived_fields_with_answers_last_and_drop_archived_without_answers(): void
    {
        $active = $this->field('Active', FormFieldType::ShortText, ['sort_order' => 1]);
        $old = $this->field('Old', FormFieldType::ShortText, ['sort_order' => 0, 'archived_at' => now()]);
        $this->field('Unused', FormFieldType::ShortText, ['archived_at' => now()]);
        $this->submission()->answers()->create(['form_field_id' => $old->id, 'value' => 'kept']);

        $this->assertSame([$active->id, $old->id], $this->table()->columns()->pluck('id')->all());
    }

    public function test_column_label_marks_removed_fields(): void
    {
        $active = $this->field('Active', FormFieldType::ShortText);
        $old = $this->field('Old', FormFieldType::ShortText, ['archived_at' => now()]);

        $this->assertSame('Active', $this->table()->columnLabel($active));
        $this->assertSame('Old (removed)', $this->table()->columnLabel($old));
    }

    public function test_cell_text_for_missing_text_choice_file_and_deleted_file_answers(): void
    {
        $text = $this->field('Text', FormFieldType::ShortText);
        $size = $this->field('Size', FormFieldType::MultipleChoice);
        $proof = $this->field('Proof', FormFieldType::File);
        $small = $size->options()->create(['label' => 'S', 'sort_order' => 0]);
        $large = $size->options()->create(['label' => 'L', 'sort_order' => 1]);
        $submission = $this->submission();
        $textAnswer = $submission->answers()->create(['form_field_id' => $text->id, 'value' => '=1+1']);
        $choice = $submission->answers()->create(['form_field_id' => $size->id]);
        $choice->options()->sync([$large->id, $small->id]);
        $file = $submission->answers()->create(['form_field_id' => $proof->id, 'file_path' => 'a/b.pdf', 'file_name' => 'b.pdf']);
        $gone = $this->submission()->answers()->create(['form_field_id' => $proof->id, 'file_name' => 'x.pdf', 'file_deleted_at' => now()]);

        $table = $this->table();

        $this->assertSame('', $table->cell(null));
        $this->assertSame('=1+1', $table->cell($textAnswer));
        $this->assertSame('S; L', $table->cell($choice->load('options')));
        $this->assertSame('b.pdf', $table->cell($file));
        $this->assertSame(FormAnswersSheetExport::DELETED_FILE_TEXT, $table->cell($gone));
    }

    public function test_file_url_only_for_existing_files(): void
    {
        $proof = $this->field('Proof', FormFieldType::File);
        $text = $this->field('Text', FormFieldType::ShortText);
        $submission = $this->submission();
        $file = $submission->answers()->create(['form_field_id' => $proof->id, 'file_path' => 'a/b.pdf', 'file_name' => 'b.pdf']);
        $gone = $this->submission()->answers()->create(['form_field_id' => $proof->id, 'file_name' => 'x.pdf', 'file_deleted_at' => now()]);
        $plain = $submission->answers()->create(['form_field_id' => $text->id, 'value' => 'hi']);

        $table = $this->table();

        $this->assertSame(route('form-answers.file', $file), $table->fileUrl($file));
        $this->assertNull($table->fileUrl($gone));
        $this->assertNull($table->fileUrl($plain));
        $this->assertNull($table->fileUrl(null));
    }

    public function test_submissions_skip_missing_attendees_and_sort_by_last_then_first_name(): void
    {
        $this->submission(['first_name' => 'Zed', 'last_name' => 'Alpha']);
        $this->submission(['first_name' => 'Amy', 'last_name' => 'alpha']);
        $this->submission(['first_name' => 'Bob', 'last_name' => 'Beta']);
        $orphan = $this->submission(['first_name' => 'Gone', 'last_name' => 'Gamma']);
        $orphan->attendee->delete();

        $names = $this->table()->submissions()->get()->map(fn ($s) => $s->attendee->first_name)->all();

        $this->assertSame(['Amy', 'Zed', 'Bob'], $names);
    }

    public function test_search_matches_name_or_email_and_treats_percent_literally(): void
    {
        $this->submission(['first_name' => 'Pct', 'last_name' => '100%', 'email_address' => 'a@example.test']);
        $this->submission(['first_name' => 'Plain', 'last_name' => 'Person', 'email_address' => 'plain@example.test']);

        $table = $this->table();

        $this->assertCount(1, $table->submissions('%')->get());
        $this->assertCount(1, $table->submissions('plain@')->get());
        $this->assertCount(1, $table->submissions('Person')->get());
        $this->assertCount(2, $table->submissions('')->get());
    }

    public function test_search_matches_a_full_name_in_any_order_and_the_middle_name(): void
    {
        $this->submission(['first_name' => 'Ada', 'middle_name' => 'Lovelace', 'last_name' => 'Cruz']);
        $this->submission(['first_name' => 'Bob', 'middle_name' => null, 'last_name' => 'Stone']);

        $table = $this->table();

        $this->assertCount(1, $table->submissions('Ada Cruz')->get());
        $this->assertCount(1, $table->submissions('cruz ada')->get());
        $this->assertCount(1, $table->submissions('lovelace')->get());
        $this->assertCount(0, $table->submissions('Ada Stone')->get());
    }
}
