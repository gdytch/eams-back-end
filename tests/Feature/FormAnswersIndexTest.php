<?php

namespace Tests\Feature;

use App\Enums\FormFieldType;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class FormAnswersIndexTest extends TestCase
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

    private function url(?Form $form = null, ?Event $event = null): string
    {
        return '/api/v1/events/'.($event ?? $this->event)->id.'/forms/'.($form ?? $this->form)->id.'/answers';
    }

    private function answers(array $query = [], ?User $user = null)
    {
        return $this->actingAs($user ?? User::factory()->orgAdmin()->for($this->org)->create(), 'sanctum')
            ->getJson($this->url().($query ? '?'.http_build_query($query) : ''));
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

    public function test_returns_columns_rows_and_meta(): void
    {
        $shirt = $this->field('T-shirt size', FormFieldType::ShortText);
        $submission = $this->submission(['first_name' => 'Ada', 'middle_name' => null, 'last_name' => 'Cruz', 'email_address' => 'ada@example.test']);
        $submission->answers()->create(['form_field_id' => $shirt->id, 'value' => 'M']);

        $response = $this->answers()->assertOk();

        $response->assertJsonPath('columns', [['id' => $shirt->id, 'label' => 'T-shirt size', 'removed' => false]]);
        $response->assertJsonPath('data.0.attendee.id', $submission->attendee_id);
        $response->assertJsonPath('data.0.attendee.name', 'Ada Cruz');
        $response->assertJsonPath('data.0.attendee.email', 'ada@example.test');
        $response->assertJsonPath('data.0.attendee.registration_status', 'registered');
        $response->assertJsonPath("data.0.cells.{$shirt->id}", ['text' => 'M', 'file_url' => null]);
        $response->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 1, 'per_page' => 25, 'total' => 1]);
    }

    public function test_rows_carry_the_organization_level_with_union_or_mission(): void
    {
        $union = Union::factory()->create(['organization_id' => $this->org->id]);
        $this->submission(['last_name' => 'Aaa', 'organization_level' => 'union', 'union_id' => $union->id]);
        $this->submission(['last_name' => 'Zzz', 'organization_level' => null, 'union_id' => null, 'mission_id' => null]);

        $response = $this->answers()->assertOk();

        $response->assertJsonPath('data.0.attendee.organization_level', 'union');
        $response->assertJsonPath('data.0.attendee.union.code', $union->code);
        $response->assertJsonPath('data.0.attendee.mission', null);
        $response->assertJsonPath('data.1.attendee.organization_level', null);
        $response->assertJsonPath('data.1.attendee.union', null);
    }

    public function test_cells_cover_choice_file_deleted_file_and_missing_answers(): void
    {
        $size = $this->field('Size', FormFieldType::MultipleChoice, ['sort_order' => 0]);
        $proof = $this->field('Proof', FormFieldType::File, ['sort_order' => 1]);
        $note = $this->field('Note', FormFieldType::ShortText, ['sort_order' => 2]);
        $small = $size->options()->create(['label' => 'S', 'sort_order' => 0]);
        $large = $size->options()->create(['label' => 'L', 'sort_order' => 1]);
        $first = $this->submission(['last_name' => 'Aaa']);
        $first->answers()->create(['form_field_id' => $size->id])->options()->sync([$large->id, $small->id]);
        $kept = $first->answers()->create(['form_field_id' => $proof->id, 'file_path' => 'form-answers/1/a.pdf', 'file_name' => 'proof.pdf']);
        $second = $this->submission(['last_name' => 'Bbb']);
        $second->answers()->create(['form_field_id' => $proof->id, 'file_name' => 'gone.pdf', 'file_deleted_at' => now()]);
        $second->answers()->create(['form_field_id' => $note->id, 'value' => '=1+1']);

        $data = $this->answers()->assertOk()->json('data');

        $this->assertSame(['text' => 'S; L', 'file_url' => null], $data[0]['cells'][$size->id]);
        $this->assertSame(['text' => 'proof.pdf', 'file_url' => route('form-answers.file', $kept)], $data[0]['cells'][$proof->id]);
        $this->assertSame(['text' => '', 'file_url' => null], $data[0]['cells'][$note->id]);
        $this->assertSame(['text' => 'File deleted (event archived)', 'file_url' => null], $data[1]['cells'][$proof->id]);
        $this->assertSame('=1+1', $data[1]['cells'][$note->id]['text']);
    }

    public function test_removed_field_column_is_flagged(): void
    {
        $old = $this->field('Old question', FormFieldType::ShortText, ['archived_at' => now()]);
        $this->field('Unused removed', FormFieldType::ShortText, ['archived_at' => now()]);
        $this->submission()->answers()->create(['form_field_id' => $old->id, 'value' => 'kept']);

        $this->answers()->assertOk()->assertJsonPath('columns', [['id' => $old->id, 'label' => 'Old question', 'removed' => true]]);
    }

    public function test_search_filters_rows_and_percent_is_literal(): void
    {
        $this->field('Note', FormFieldType::ShortText);
        $this->submission(['first_name' => 'Pct', 'last_name' => '100%']);
        $this->submission(['first_name' => 'Plain', 'middle_name' => null, 'last_name' => 'Person', 'email_address' => 'plain@example.test']);

        $this->assertSame(1, $this->answers(['search' => '%'])->assertOk()->json('meta.total'));
        $this->assertSame(['Plain Person'], collect($this->answers(['search' => 'plain@'])->json('data'))->pluck('attendee.name')->map(fn ($n) => preg_replace('/\s+/', ' ', $n))->all());
        $this->assertSame(2, $this->answers()->json('meta.total'));
    }

    public function test_pagination_and_per_page_is_clamped_to_1_and_100(): void
    {
        foreach (['Aaa', 'Bbb', 'Ccc'] as $last) {
            $this->submission(['last_name' => $last]);
        }

        $page = $this->answers(['per_page' => 2, 'page' => 2])->assertOk();
        $this->assertCount(1, $page->json('data'));
        $this->assertSame(['current_page' => 2, 'last_page' => 2, 'per_page' => 2, 'total' => 3], $page->json('meta'));

        $this->assertSame(100, $this->answers(['per_page' => 5000])->json('meta.per_page'));
        $this->assertSame(1, $this->answers(['per_page' => 0])->json('meta.per_page'));
    }

    public function test_form_with_no_fields_and_form_with_no_submissions_return_empty_shapes(): void
    {
        $empty = $this->answers()->assertOk();
        $this->assertSame([], $empty->json('columns'));
        $this->assertSame([], $empty->json('data'));
        $this->assertSame(0, $empty->json('meta.total'));

        $this->submission();
        $noFields = $this->answers()->assertOk();
        $this->assertSame([], $noFields->json('columns'));
        $this->assertCount(1, $noFields->json('data'));
        $this->assertEmpty($noFields->json('data.0.cells'));
    }

    public function test_access_is_org_admin_of_the_organization_and_super_admin(): void
    {
        $this->getJson($this->url())->assertUnauthorized();

        $this->actingAs(User::factory()->checker()->for($this->org)->create(), 'sanctum')->getJson($this->url())->assertForbidden();
        $this->actingAs(User::factory()->attendee()->for($this->org)->create(), 'sanctum')->getJson($this->url())->assertNotFound();
        $this->actingAs(User::factory()->orgAdmin()->create(), 'sanctum')->getJson($this->url())->assertNotFound();

        $this->actingAs(User::factory()->orgAdmin()->for($this->org)->create(), 'sanctum')->getJson($this->url())->assertOk();
        $this->actingAs(User::factory()->superAdmin()->create(), 'sanctum')->getJson($this->url())->assertOk();
    }

    public function test_form_from_another_event_returns_404(): void
    {
        $otherEvent = Event::factory()->for($this->org)->create();
        $otherForm = Form::factory()->for($otherEvent)->published()->create();

        $this->actingAs(User::factory()->orgAdmin()->for($this->org)->create(), 'sanctum')
            ->getJson($this->url($otherForm))->assertNotFound();
    }

    public function test_works_on_an_archived_event(): void
    {
        $this->event->forceFill(['archived_at' => now()])->save();

        $this->answers()->assertOk();
    }
}
