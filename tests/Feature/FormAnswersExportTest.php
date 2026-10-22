<?php

namespace Tests\Feature;

use App\Enums\FormFieldType;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class FormAnswersExportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $org;

    private Event $event;

    private Form $form;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->event = Event::factory()->for($this->org)->create();
        $this->form = Form::factory()->for($this->event)->published()->create(['title' => 'Shirt order']);
    }

    private function url(?Event $event = null): string
    {
        return '/api/v1/events/'.($event ?? $this->event)->id.'/forms/export';
    }

    private function sheets(?User $user = null): Spreadsheet
    {
        $response = $this->actingAs($user ?? User::factory()->orgAdmin()->for($this->org)->create(), 'sanctum')->get($this->url());
        $response->assertOk();

        return IOFactory::load($response->baseResponse->getFile()->getPathname());
    }

    private function field(string $label, FormFieldType $type, array $extra = []): FormField
    {
        return $this->form->fields()->create(['type' => $type, 'label' => $label] + $extra);
    }

    private function submission(array $attendee = [], ?Form $form = null): FormSubmission
    {
        $attendee = Attendee::factory()->for($this->event)->create($attendee);

        return FormSubmission::create(['form_id' => ($form ?? $this->form)->id, 'attendee_id' => $attendee->id]);
    }

    public function test_columns_follow_form_order_with_removed_fields_last(): void
    {
        $name = $this->field('Name on shirt', FormFieldType::ShortText, ['sort_order' => 2]);
        $size = $this->field('Size', FormFieldType::MultipleChoice, ['sort_order' => 1]);
        $old = $this->field('Old question', FormFieldType::ShortText, ['sort_order' => 0, 'archived_at' => now()]);
        $this->field('Unused removed', FormFieldType::ShortText, ['archived_at' => now()]);
        $small = $size->options()->create(['label' => 'S', 'sort_order' => 0]);
        $large = $size->options()->create(['label' => 'L', 'sort_order' => 1]);

        $submission = $this->submission(['first_name' => 'Jane', 'middle_name' => null, 'last_name' => 'Doe', 'email_address' => 'jane@example.test']);
        $submission->answers()->create(['form_field_id' => $name->id, 'value' => '=1+1']);
        $submission->answers()->create(['form_field_id' => $old->id, 'value' => 'kept']);
        $submission->answers()->create(['form_field_id' => $size->id])->options()->sync([$large->id, $small->id]);

        $ws = $this->sheets()->getSheet(0);

        $this->assertSame('Shirt order', $ws->getTitle());
        $this->assertSame(['Name', 'Email', 'Territory', 'Registration status', 'Size', 'Name on shirt', 'Old question (removed)'], $this->rowOf($ws, 1));
        $row = $this->rowOf($ws, 2);
        $this->assertSame(['Jane Doe', 'jane@example.test'], array_slice($row, 0, 2));
        $this->assertSame('registered', $row[3]);
        $this->assertSame(['S; L', '=1+1', 'kept'], array_slice($row, 4));
        $this->assertNull($ws->getCell('A3')->getValue());
    }

    private function rowOf($ws, int $row): array
    {
        $cells = [];
        $last = Coordinate::columnIndexFromString($ws->getHighestColumn());
        for ($c = 1; $c <= $last; $c++) {
            $cells[] = $ws->getCell([$c, $row])->getValue();
        }

        return $cells;
    }

    public function test_file_answers_link_to_the_download_route_and_deleted_files_say_so(): void
    {
        $proof = $this->field('Proof', FormFieldType::File);
        $first = $this->submission(['last_name' => 'Aaa']);
        $kept = $first->answers()->create(['form_field_id' => $proof->id, 'file_path' => 'form-answers/1/a.pdf', 'file_name' => 'proof.pdf']);
        $second = $this->submission(['last_name' => 'Bbb']);
        $second->answers()->create(['form_field_id' => $proof->id, 'file_name' => 'gone.pdf', 'file_deleted_at' => now()]);

        $ws = $this->sheets()->getSheet(0);

        $this->assertSame('proof.pdf', $ws->getCell('E2')->getValue());
        $this->assertSame(route('form-answers.file', $kept), $ws->getCell('E2')->getHyperlink()->getUrl());
        $this->assertSame('File deleted (event archived)', $ws->getCell('E3')->getValue());
        $this->assertSame('', $ws->getCell('E3')->getHyperlink()->getUrl());
    }

    public function test_one_sheet_per_published_form_and_unstarted_form_has_headers_only(): void
    {
        $this->field('Name', FormFieldType::ShortText);
        $other = Form::factory()->for($this->event)->published()->create(['title' => 'Waiver']);
        $other->fields()->create(['type' => FormFieldType::ShortText, 'label' => 'Signature']);
        Form::factory()->for($this->event)->create(['title' => 'Hidden draft']);
        $this->submission();

        $book = $this->sheets();

        $this->assertSame(['Shirt order', 'Waiver'], $book->getSheetNames());
        $this->assertSame(['Name', 'Email', 'Territory', 'Registration status', 'Signature'], $this->rowOf($book->getSheet(1), 1));
        $this->assertNull($book->getSheet(1)->getCell('A2')->getValue());
    }

    public function test_workbook_without_published_forms_has_one_empty_sheet(): void
    {
        $this->form->update(['status' => 'draft']);

        $book = $this->sheets();

        $this->assertSame(1, $book->getSheetCount());
        $this->assertSame(['Name', 'Email', 'Territory', 'Registration status'], $this->rowOf($book->getSheet(0), 1));
    }

    public function test_access_is_org_admin_of_the_organization_and_super_admin(): void
    {
        $this->getJson($this->url())->assertUnauthorized();

        $this->actingAs(User::factory()->checker()->for($this->org)->create(), 'sanctum')->get($this->url())->assertForbidden();
        $this->actingAs(User::factory()->attendee()->for($this->org)->create(), 'sanctum')->get($this->url())->assertNotFound();
        $this->actingAs(User::factory()->orgAdmin()->create(), 'sanctum')->get($this->url())->assertNotFound();

        $this->actingAs(User::factory()->orgAdmin()->for($this->org)->create(), 'sanctum')->get($this->url())->assertOk();
        $this->actingAs(User::factory()->superAdmin()->create(), 'sanctum')->get($this->url())->assertOk();
    }

    public function test_export_works_on_an_archived_event_and_is_audited(): void
    {
        $this->event->forceFill(['archived_at' => now()])->save();
        $admin = User::factory()->orgAdmin()->for($this->org)->create();

        $this->actingAs($admin, 'sanctum')->get($this->url())->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'form_answers.exported', 'auditable_id' => $this->event->id, 'user_id' => $admin->id]);
    }

    public function test_only_this_events_attendees_appear(): void
    {
        $this->field('Name', FormFieldType::ShortText);
        $this->submission(['first_name' => 'Mine', 'middle_name' => null, 'last_name' => 'One']);
        $otherEvent = Event::factory()->for($this->org)->create();
        $otherForm = Form::factory()->for($otherEvent)->published()->create(['title' => 'Elsewhere']);
        $this->submission(['first_name' => 'Other'], $otherForm);

        $ws = $this->sheets()->getSheet(0);

        $this->assertSame('Mine One', $ws->getCell('A2')->getValue());
        $this->assertNull($ws->getCell('A3')->getValue());
    }
}
