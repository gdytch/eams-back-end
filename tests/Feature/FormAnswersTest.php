<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\FormFieldType;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Form;
use App\Models\FormAnswer;
use App\Models\FormField;
use App\Models\FormFieldOption;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use App\Rules\ValidImageUpload;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FormAnswersTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $org;

    private Event $event;

    private User $user;

    private Form $form;

    private FormField $name;

    private FormField $size;

    private FormField $proof;

    private FormFieldOption $small;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');

        $this->org = Organization::factory()->create();
        $this->event = Event::factory()->for($this->org)->state(['status' => EventStatus::Published])->create();
        $this->user = User::factory()->attendee()->for($this->org)->create();

        $this->form = Form::factory()->for($this->event)->published()->required()->create(['title' => 'Shirt order']);
        $this->name = $this->field($this->form, FormFieldType::ShortText, 'Name', true);
        $this->size = $this->field($this->form, FormFieldType::SingleChoice, 'Size', true);
        $this->small = $this->size->options()->create(['label' => 'S']);
        $this->size->options()->create(['label' => 'M']);
        $this->proof = $this->field($this->form, FormFieldType::File, 'Proof', true);
    }

    private function field(Form $form, FormFieldType $type, string $label, bool $required): FormField
    {
        return $form->fields()->create(['type' => $type, 'label' => $label, 'is_required' => $required]);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/public/event/{$this->event->invite_token}/registration".$path;
    }

    /** Public wizard attendee with everything but the forms done. */
    private function draft(?User $user = null): Attendee
    {
        $this->actingAs($user ?? $this->user, 'sanctum');
        $this->putJson($this->url('/basic-info'), ['first_name' => 'Jane', 'last_name' => 'Doe', 'mobile_no' => '0917'])->assertOk();
        $union = Union::factory()->create(['organization_id' => $this->org->id]);
        $this->putJson($this->url('/territory'), ['organization_level' => 'union', 'union_id' => $union->id])->assertOk();

        return Attendee::where('user_id', ($user ?? $this->user)->id)->sole();
    }

    private function pdf(string $name = 'proof.pdf', int $kb = 10): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".str_repeat('a', $kb * 1024)."\n%%EOF");
    }

    private function answers(array $answers, ?int $formId = null)
    {
        return $this->post($this->url('/forms/'.($formId ?? $this->form->id)), ['answers' => $answers], ['Accept' => 'application/json']);
    }

    private function fillAll(): void
    {
        $this->answers([
            $this->name->id => 'Jane',
            $this->size->id => $this->small->id,
            $this->proof->id => $this->pdf(),
        ])->assertOk();
    }

    public function test_save_requires_auth_and_a_basic_info_draft(): void
    {
        $this->postJson($this->url('/forms/'.$this->form->id), [])->assertUnauthorized();

        $this->actingAs($this->user, 'sanctum');
        $this->answers([$this->name->id => 'x'])->assertUnprocessable();
    }

    public function test_partial_save_stores_answers_and_keeps_steps_missing(): void
    {
        $this->draft();

        $this->answers([$this->name->id => 'Jane', $this->size->id => $this->small->id])
            ->assertOk()
            ->assertJsonPath('data.started', true)
            ->assertJsonPath('data.fields.0.answer.value', 'Jane')
            ->assertJsonPath('data.fields.1.answer.option_ids', [$this->small->id])
            ->assertJsonPath('steps.forms', ['Answer the Shirt order form']);
    }

    public function test_complete_form_clears_the_forms_step_and_finish_registers(): void
    {
        $this->draft();
        $this->fillAll();

        $this->postJson($this->url('/finish'))->assertOk();
        $this->assertNotNull(Attendee::where('user_id', $this->user->id)->sole()->registration_completed_at);
    }

    public function test_answers_replace_and_blank_clears(): void
    {
        $this->draft();
        $this->answers([$this->name->id => 'Jane'])->assertOk();
        $this->answers([$this->name->id => 'Janet'])->assertOk()->assertJsonPath('data.fields.0.answer.value', 'Janet');
        $this->answers([$this->name->id => ''])->assertOk()->assertJsonPath('data.fields.0.answer', null);
        $this->assertSame(0, FormAnswer::count());
    }

    public function test_field_rules_follow_the_definition(): void
    {
        $this->draft();
        $number = $this->field($this->form, FormFieldType::Number, 'Age', false);
        $multi = $this->field($this->form, FormFieldType::MultipleChoice, 'Extras', false);
        $one = $multi->options()->create(['label' => 'Hat']);
        $other = FormFieldOption::create(['form_field_id' => $this->size->id, 'label' => 'XL']);
        $other->update(['archived_at' => now()]);

        $this->answers([$number->id => 'abc'])->assertJsonValidationErrors(["answers.{$number->id}"]);
        $this->answers([$this->name->id => str_repeat('a', 256)])->assertJsonValidationErrors(["answers.{$this->name->id}"]);
        $this->answers([$this->size->id => 999999])->assertJsonValidationErrors(["answers.{$this->size->id}"]);
        $this->answers([$this->size->id => $other->id])->assertJsonValidationErrors(["answers.{$this->size->id}"]);
        $this->answers([$multi->id => [$one->id, 999999]])->assertJsonValidationErrors(["answers.{$multi->id}.1"]);

        $this->answers([$number->id => '12.5', $multi->id => [$one->id]])->assertOk();
        $this->assertSame('12.5', FormAnswer::where('form_field_id', $number->id)->value('value'));
    }

    public function test_unknown_or_archived_field_is_rejected(): void
    {
        $this->draft();
        $this->name->update(['archived_at' => now()]);

        $this->answers([$this->name->id => 'x'])->assertJsonValidationErrors(["answers.{$this->name->id}"]);
        $this->answers([987654 => 'x'])->assertJsonValidationErrors(['answers.987654']);
    }

    public function test_unpublished_or_other_event_form_is_not_found(): void
    {
        $this->draft();
        $draft = Form::factory()->for($this->event)->create();
        $foreign = Form::factory()->published()->create();

        $this->answers([], $draft->id)->assertNotFound();
        $this->answers([], $foreign->id)->assertNotFound();
    }

    public function test_file_over_5_mb_or_docx_is_rejected(): void
    {
        $this->draft();

        $this->answers([$this->proof->id => $this->pdf('big.pdf', 6 * 1024)])->assertJsonValidationErrors(["answers.{$this->proof->id}"]);
        $docx = UploadedFile::fake()->createWithContent('a.docx', "PK\x03\x04".str_repeat('x', 200));
        $this->answers([$this->proof->id => $docx])->assertJsonValidationErrors(["answers.{$this->proof->id}"]);
        $this->answers([$this->proof->id => 'not a file'])->assertJsonValidationErrors(["answers.{$this->proof->id}"]);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_png_jpeg_and_pdf_are_accepted_and_stored_privately(): void
    {
        $this->draft();

        $this->answers([$this->proof->id => UploadedFile::fake()->image('p.png', 20, 20)])->assertOk();
        $this->answers([$this->proof->id => UploadedFile::fake()->image('p.jpg', 20, 20)])->assertOk();
        $this->answers([$this->proof->id => $this->pdf()])->assertOk();

        $answer = FormAnswer::sole();
        $this->assertStringEndsWith('.pdf', $answer->file_path);
        $this->assertSame('proof.pdf', $answer->file_name);
        Storage::disk('local')->assertExists($answer->file_path);
        $this->assertCount(1, Storage::disk('local')->allFiles(), 're-upload replaces the old file');
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_file_keeps_when_the_key_is_absent(): void
    {
        $this->draft();
        $this->answers([$this->proof->id => $this->pdf()])->assertOk();
        $path = FormAnswer::sole()->file_path;

        $this->answers([$this->name->id => 'Jane'])->assertOk();
        $this->assertSame($path, FormAnswer::where('form_field_id', $this->proof->id)->value('file_path'));
    }

    public function test_download_is_limited_to_owner_org_admin_and_super_admin(): void
    {
        $this->draft();
        $this->answers([$this->proof->id => $this->pdf()])->assertOk();
        $answer = FormAnswer::sole();
        $url = "/api/v1/form-answers/{$answer->id}/file";

        $this->actingAs(User::factory()->attendee()->for($this->org)->create(), 'sanctum')->getJson($url)->assertForbidden();
        $this->actingAs(User::factory()->orgAdmin()->for(Organization::factory()->create())->create(), 'sanctum')->getJson($url)->assertForbidden();
        $this->actingAs(User::factory()->checker()->for($this->org)->create(), 'sanctum')->getJson($url)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson($url)->assertUnauthorized();

        foreach ([$this->user, User::factory()->orgAdmin()->for($this->org)->create(), User::factory()->superAdmin()->create()] as $allowed) {
            $this->actingAs($allowed, 'sanctum');
            $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        }
    }

    public function test_download_of_a_removed_file_is_not_found(): void
    {
        $this->draft();
        $this->answers([$this->proof->id => $this->pdf()])->assertOk();
        $answer = FormAnswer::sole();
        $answer->update(['file_path' => null, 'file_deleted_at' => now()]);

        $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/form-answers/{$answer->id}/file")->assertNotFound();
    }

    public function test_skipped_optional_form_does_not_block_finish_but_a_started_one_does(): void
    {
        $this->form->update(['is_required' => false]);
        $this->draft();

        $this->postJson($this->url('/forms/'.$this->form->id.'/skip'))->assertOk()->assertJsonPath('data.started', false)->assertJsonPath('steps.forms', []);
        $this->postJson($this->url('/finish'))->assertOk();
    }

    public function test_started_optional_form_blocks_finish_until_required_fields_are_answered(): void
    {
        $this->form->update(['is_required' => false]);
        $this->draft();
        $this->answers([$this->name->id => 'Jane'])->assertOk()->assertJsonPath('steps.forms', ['Answer the Shirt order form']);

        $this->postJson($this->url('/finish'))->assertUnprocessable()->assertJsonPath('errors.steps', ['forms']);
        $this->postJson($this->url('/forms/'.$this->form->id.'/skip'))->assertUnprocessable();

        $this->fillAll();
        $this->postJson($this->url('/finish'))->assertOk();
    }

    public function test_all_blank_save_does_not_start_an_optional_form(): void
    {
        $this->form->update(['is_required' => false]);
        $this->draft();

        $this->answers([$this->name->id => ''])->assertOk()->assertJsonPath('data.started', false)->assertJsonPath('steps.forms', []);
        $this->assertDatabaseCount('form_submissions', 0);
    }

    public function test_required_form_cannot_be_skipped_and_blocks_finish(): void
    {
        $this->draft();

        $this->postJson($this->url('/forms/'.$this->form->id.'/skip'))->assertUnprocessable();
        $this->postJson($this->url('/finish'))->assertUnprocessable()->assertJsonPath('errors.steps', ['forms']);
    }

    public function test_archived_required_field_does_not_count(): void
    {
        $this->draft();
        $this->proof->update(['archived_at' => now()]);
        $this->answers([$this->name->id => 'Jane', $this->size->id => $this->small->id])->assertOk()->assertJsonPath('steps.forms', []);
    }

    public function test_attendee_lists_published_forms_with_own_answers_only(): void
    {
        $this->draft();
        Form::factory()->for($this->event)->create(['title' => 'Draft form']);
        $this->answers([$this->name->id => 'Jane', $this->proof->id => $this->pdf()])->assertOk();

        $this->getJson($this->url('/forms'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.fields.0.answer.value', 'Jane')
            ->assertJsonPath('data.0.fields.2.answer.file.name', 'proof.pdf');

        $other = User::factory()->attendee()->for($this->org)->create();
        $this->draft($other);
        $this->getJson($this->url('/forms'))->assertOk()->assertJsonPath('data.0.fields.0.answer', null);
    }

    public function test_fully_registered_attendee_cannot_edit_but_admin_can_with_audit(): void
    {
        $this->draft();
        $this->fillAll();
        $this->postJson($this->url('/finish'))->assertOk();
        $attendee = Attendee::where('user_id', $this->user->id)->sole();

        $this->answers([$this->name->id => 'Changed'])->assertForbidden();
        $this->assertSame('Jane', FormAnswer::where('form_field_id', $this->name->id)->value('value'));

        $admin = User::factory()->orgAdmin()->for($this->org)->create();
        $this->actingAs($admin, 'sanctum')
            ->post("/api/v1/attendees/{$attendee->id}/forms/{$this->form->id}", ['answers' => [$this->name->id => 'Changed']], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.fields.0.answer.value', 'Changed')->assertJsonPath('steps.forms', []);

        $log = AuditLog::where('action', 'attendee.form_answers_changed')->sole();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('Jane', $log->changes['before'][$this->name->id]);
        $this->assertSame('Changed', $log->changes['after'][$this->name->id]);
        $this->assertNotNull($attendee->fresh()->registration_completed_at);
    }

    public function test_admin_wizard_authorization_and_archived_event(): void
    {
        $attendee = Attendee::factory()->for($this->event)->create();
        $url = "/api/v1/attendees/{$attendee->id}/forms/{$this->form->id}";
        $body = ['answers' => [$this->name->id => 'x']];
        $json = ['Accept' => 'application/json'];

        $this->actingAs(User::factory()->checker()->for($this->org)->create(), 'sanctum')->post($url, $body, $json)->assertForbidden();
        $this->actingAs(User::factory()->orgAdmin()->for(Organization::factory()->create())->create(), 'sanctum')->post($url, $body, $json)->assertNotFound();

        $this->actingAs(User::factory()->orgAdmin()->for($this->org)->create(), 'sanctum');
        $this->getJson("/api/v1/attendees/{$attendee->id}/forms")->assertOk()->assertJsonPath('data.0.title', 'Shirt order');
        $this->post($url, $body, $json)->assertOk();

        $this->event->forceFill(['archived_at' => now()])->save();
        $this->post($url, ['answers' => [$this->name->id => 'y']], $json)->assertForbidden();
    }

    public function test_admin_skip_follows_the_same_rules(): void
    {
        $attendee = Attendee::factory()->for($this->event)->create();
        $optional = Form::factory()->for($this->event)->published()->create();
        $this->actingAs(User::factory()->orgAdmin()->for($this->org)->create(), 'sanctum');

        $this->postJson("/api/v1/attendees/{$attendee->id}/forms/{$this->form->id}/skip")->assertUnprocessable();
        $this->postJson("/api/v1/attendees/{$attendee->id}/forms/{$optional->id}/skip")->assertOk()->assertJsonPath('data.started', false);
    }

    public function test_pdf_is_only_allowed_when_asked_for(): void
    {
        $this->actingAs($this->user, 'sanctum');
        $photo = ['photo' => $this->pdf()];
        $rule = new ValidImageUpload;
        $failed = false;
        $rule->validate('photo', $photo['photo'], function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, 'default rule still rejects a PDF');
    }
}
