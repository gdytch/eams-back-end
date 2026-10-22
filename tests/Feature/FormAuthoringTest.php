<?php

namespace Tests\Feature;

use App\Enums\FormFieldType;
use App\Jobs\RecomputeEventCompletenessJob;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\Form;
use App\Models\FormAnswer;
use App\Models\FormField;
use App\Models\FormFieldOption;
use App\Models\FormSubmission;
use App\Models\Organization;
use App\Models\User;
use App\Services\RegistrationCompleteness;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FormAuthoringTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $org;

    private Event $event;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->event = Event::factory()->for($this->org)->create();
        $this->url = "/api/v1/events/{$this->event->id}/forms";
        $this->actingAs(User::factory()->orgAdmin()->for($this->org)->create(), 'sanctum');
    }

    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Shirt order',
            'description' => 'Pick your size',
            'is_required' => false,
            'fields' => [
                ['type' => 'single_choice', 'label' => 'Size', 'is_required' => true, 'options' => [['label' => 'S'], ['label' => 'M'], ['label' => 'L']]],
                ['type' => 'file', 'label' => 'Payment proof', 'help_text' => 'PNG or PDF'],
            ],
            ...$overrides,
        ];
    }

    /** Create a form through the API and return its current definition as an update payload. */
    private function create(array $overrides = []): array
    {
        $json = $this->postJson($this->url, $this->payload($overrides))->assertCreated()->json('data');

        return $json;
    }

    /** Round-trip the stored definition into an update payload, keeping ids. */
    private function editable(array $form): array
    {
        return [
            'title' => $form['title'],
            'description' => $form['description'],
            'is_required' => $form['is_required'],
            'fields' => collect($form['fields'])->whereNull('archived_at')->map(fn ($f) => [
                'id' => $f['id'], 'type' => $f['type'], 'label' => $f['label'], 'help_text' => $f['help_text'], 'is_required' => $f['is_required'],
                'options' => collect($f['options'])->whereNull('archived_at')->map(fn ($o) => ['id' => $o['id'], 'label' => $o['label']])->values()->all(),
            ])->values()->all(),
        ];
    }

    private function answer(Form $form, FormField $field, array $attributes = [], ?Attendee $attendee = null): FormAnswer
    {
        $attendee ??= Attendee::factory()->for($this->event)->create();
        $submission = FormSubmission::firstOrCreate(['form_id' => $form->id, 'attendee_id' => $attendee->id]);

        return $submission->answers()->create(['form_field_id' => $field->id, ...$attributes]);
    }

    public function test_create_show_list_and_order(): void
    {
        $first = $this->create();
        $second = $this->create(['title' => 'Meal']);

        $this->assertSame(['draft'], [$first['status']]);
        $this->assertSame([0, 1], [$first['sort_order'], $second['sort_order']]);
        $this->assertSame(['S', 'M', 'L'], collect($first['fields'][0]['options'])->pluck('label')->all());
        $this->assertNotNull($first['fields'][0]['id']);

        $this->getJson("{$this->url}/{$first['id']}")->assertOk()->assertJsonPath('data.title', 'Shirt order');
        $this->getJson($this->url)->assertOk()->assertJsonCount(2, 'data');

        $this->putJson("{$this->url}/order", ['form_ids' => [$second['id'], $first['id']]])->assertOk()
            ->assertJsonPath('data.0.title', 'Meal');
        $this->putJson("{$this->url}/order", ['form_ids' => [$second['id']]])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'form.created']);
    }

    public function test_validation_of_fields_and_options(): void
    {
        $this->postJson($this->url, $this->payload(['fields' => [['type' => 'bogus', 'label' => 'x']]]))
            ->assertUnprocessable()->assertJsonValidationErrors('fields.0.type');
        $this->postJson($this->url, $this->payload(['fields' => [['type' => 'single_choice', 'label' => 'x']]]))
            ->assertUnprocessable()->assertJsonValidationErrors('fields.0.options');
        $this->postJson($this->url, $this->payload(['fields' => [['id' => 5, 'type' => 'short_text', 'label' => 'x']]]))
            ->assertUnprocessable()->assertJsonValidationErrors('fields.0.id');
        $this->postJson($this->url, ['title' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('fields');

        $form = $this->create();
        $body = $this->editable($form);
        $body['fields'][0]['id'] = 999999;
        $this->putJson("{$this->url}/{$form['id']}", $body)->assertUnprocessable()->assertJsonValidationErrors('fields.0.id');
    }

    public function test_every_field_type_is_accepted(): void
    {
        $fields = collect(FormFieldType::cases())->map(fn ($type) => [
            'type' => $type->value, 'label' => $type->value,
            ...($type->hasOptions() ? ['options' => [['label' => 'A']]] : []),
        ])->all();

        $this->postJson($this->url, $this->payload(['fields' => $fields]))->assertCreated()->assertJsonCount(6, 'data.fields');
    }

    public function test_edit_keeps_ids_and_replaces_labels_and_order(): void
    {
        $form = $this->create();
        $body = $this->editable($form);
        $body['fields'] = array_reverse($body['fields']);
        $body['fields'][1]['label'] = 'T-shirt size';
        $body['fields'][1]['options'] = array_reverse($body['fields'][1]['options']);
        $body['fields'][1]['options'][0]['label'] = 'Large';

        $result = $this->putJson("{$this->url}/{$form['id']}", $body)->assertOk()->json('data');

        $this->assertSame([$form['fields'][1]['id'], $form['fields'][0]['id']], collect($result['fields'])->pluck('id')->all());
        $this->assertSame('T-shirt size', $result['fields'][1]['label']);
        $this->assertSame(['Large', 'M', 'S'], collect($result['fields'][1]['options'])->pluck('label')->all());
        $this->assertSame($form['fields'][0]['options'][2]['id'], $result['fields'][1]['options'][0]['id']);
    }

    public function test_unanswered_removals_delete_and_a_free_type_change_drops_options(): void
    {
        $form = $this->create();
        $body = $this->editable($form);
        array_pop($body['fields']);
        $body['fields'][0]['type'] = 'short_text';
        $this->putJson("{$this->url}/{$form['id']}", $body)->assertOk()->assertJsonCount(1, 'data.fields')
            ->assertJsonPath('data.fields.0.options', []);

        $this->assertDatabaseCount('form_fields', 1);
        $this->assertDatabaseCount('form_field_options', 0);
    }

    public function test_changing_an_answered_fields_type_returns_422(): void
    {
        $form = $this->create();
        $field = FormField::find($form['fields'][0]['id']);
        $this->answer(Form::find($form['id']), $field, ['value' => 'x']);

        $body = $this->editable($form);
        $body['fields'][0]['type'] = 'short_text';
        $this->putJson("{$this->url}/{$form['id']}", $body)->assertUnprocessable()->assertJsonValidationErrors('fields.0.type');
        $this->assertSame(FormFieldType::SingleChoice, $field->fresh()->type);

        // Changing it within the same type, or any unanswered field's type, is fine.
        $body['fields'][0]['type'] = 'single_choice';
        $body['fields'][1]['type'] = 'long_text';
        $this->putJson("{$this->url}/{$form['id']}", $body)->assertOk();
    }

    public function test_removing_an_answered_field_or_option_archives_it(): void
    {
        $form = $this->create();
        $model = Form::find($form['id']);
        $field = FormField::find($form['fields'][0]['id']);
        $option = FormFieldOption::find($form['fields'][0]['options'][1]['id']);
        $this->answer($model, $field)->options()->attach($option);
        $this->answer($model, FormField::find($form['fields'][1]['id']), ['file_path' => 'a.pdf', 'file_name' => 'a.pdf']);

        // Drop the answered option and the answered file field.
        $body = $this->editable($form);
        $body['fields'][0]['options'] = [$body['fields'][0]['options'][0], $body['fields'][0]['options'][2]];
        array_pop($body['fields']);
        $result = $this->putJson("{$this->url}/{$form['id']}", $body)->assertOk()->json('data');

        $this->assertNull($result['fields'][0]['archived_at']);
        $this->assertNotNull(collect($result['fields'][0]['options'])->firstWhere('id', $option->id)['archived_at']);
        $this->assertNotNull(collect($result['fields'])->firstWhere('id', $form['fields'][1]['id'])['archived_at']);
        $this->assertDatabaseCount('form_answers', 2);

        // The archived option can no longer be referenced or edited, and active options are all that remain editable.
        $body = $this->editable($result);
        $body['fields'][0]['options'][] = ['id' => $option->id, 'label' => 'back'];
        $this->putJson("{$this->url}/{$form['id']}", $body)->assertUnprocessable()->assertJsonValidationErrors('fields.0.options.2.id');
    }

    public function test_adding_optional_things_never_needs_confirmation(): void
    {
        Queue::fake();
        $form = $this->create();
        Form::find($form['id'])->update(['status' => 'published']);
        Attendee::factory()->for($this->event)->create();

        $body = $this->editable($form);
        $body['fields'][] = ['type' => 'number', 'label' => 'Quantity'];
        $body['fields'][0]['options'][] = ['label' => 'XL'];
        $this->putJson("{$this->url}/{$form['id']}", $body)->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_adding_a_required_field_to_a_published_form_asks_then_recomputes(): void
    {
        Queue::fake();
        $form = $this->create(['is_required' => true, 'fields' => [['type' => 'short_text', 'label' => 'Name on shirt', 'is_required' => true]]]);
        $model = Form::find($form['id']);
        $attendees = Attendee::factory()->for($this->event)->count(2)->create();
        Attendee::factory()->for($this->event)->draft()->create();
        $this->postJson("{$this->url}/{$form['id']}/publish", ['confirm' => true])->assertOk();
        (new RecomputeEventCompletenessJob($this->event))->handle(app(RegistrationCompleteness::class));
        $this->assertNull($attendees[0]->fresh()->registration_completed_at, 'published required form made them incomplete');
        foreach ($attendees as $a) {
            $this->answer($model, FormField::find($form['fields'][0]['id']), ['value' => 'Sam'], $a);
            $a->fresh()->forceFill(['registration_confirmed_at' => now()])->save();
        }
        $this->assertNotNull($attendees[0]->fresh()->registration_completed_at);
        Queue::fake();

        $body = $this->editable($form);
        $body['fields'][] = ['type' => 'short_text', 'label' => 'Phone', 'is_required' => true];
        $this->putJson("{$this->url}/{$form['id']}", $body)->assertStatus(409)
            ->assertJson(['requires_confirmation' => true, 'would_become_incomplete' => 2]);
        $this->assertDatabaseCount('form_fields', 1);
        Queue::assertNothingPushed();

        $this->putJson("{$this->url}/{$form['id']}", [...$body, 'confirm' => true])->assertOk();
        $this->assertDatabaseCount('form_fields', 2);
        Queue::assertPushed(RecomputeEventCompletenessJob::class);
    }

    public function test_making_a_field_required_counts_and_a_draft_edit_does_not(): void
    {
        Queue::fake();
        $form = $this->create(['fields' => [['type' => 'short_text', 'label' => 'Note']]]);
        $body = $this->editable($form);
        $body['fields'][0]['is_required'] = true;
        $this->putJson("{$this->url}/{$form['id']}", $body)->assertOk();
        Queue::assertNothingPushed();

        $other = $this->create(['fields' => [['type' => 'short_text', 'label' => 'Note']]]);
        Form::find($other['id'])->update(['status' => 'published']);
        $this->postJson("{$this->url}/{$other['id']}/unpublish")->assertOk()->assertJsonPath('data.status', 'draft');
        $this->postJson("{$this->url}/{$other['id']}/publish")->assertOk()->assertJsonPath('data.status', 'published');
        Queue::assertNothingPushed();
        $body = $this->editable($other);
        $body['fields'][0]['is_required'] = true;
        $this->putJson("{$this->url}/{$other['id']}", $body)->assertOk();
        Queue::assertPushed(RecomputeEventCompletenessJob::class);
    }

    public function test_publishing_a_required_form_returns_the_count_and_makes_registrants_incomplete_after_confirm(): void
    {
        Queue::fake();
        $form = $this->create(['is_required' => true]);
        $attendees = Attendee::factory()->for($this->event)->count(3)->create();
        Attendee::factory()->for($this->event)->draft()->create();

        $this->postJson("{$this->url}/{$form['id']}/publish")->assertStatus(409)
            ->assertJson(['requires_confirmation' => true, 'would_become_incomplete' => 3]);
        $this->assertSame('draft', Form::find($form['id'])->status->value);
        Queue::assertNothingPushed();

        $this->postJson("{$this->url}/{$form['id']}/publish", ['confirm' => true])->assertOk()->assertJsonPath('data.status', 'published');
        Queue::assertPushed(RecomputeEventCompletenessJob::class);

        // The queued job, run for real, flips them.
        (new RecomputeEventCompletenessJob($this->event))->handle(app(RegistrationCompleteness::class));
        $this->assertNull($attendees[0]->fresh()->registration_completed_at);
        $this->assertNotNull($attendees[0]->fresh()->first_registered_at);
        $this->assertContains('Answer the Shirt order form', app(RegistrationCompleteness::class)->missingLabels($attendees[0]->fresh()));
    }

    public function test_publishing_without_registrants_or_an_optional_form_needs_no_confirmation(): void
    {
        $optional = $this->create();
        Attendee::factory()->for($this->event)->create();
        $this->postJson("{$this->url}/{$optional['id']}/publish")->assertOk();
        $this->assertNotNull(Attendee::first()->registration_completed_at);

        $required = $this->create(['is_required' => true]);
        Attendee::query()->delete();
        $this->postJson("{$this->url}/{$required['id']}/publish")->assertOk();
    }

    public function test_started_optional_form_applies_its_required_fields(): void
    {
        $form = $this->create();
        Form::find($form['id'])->update(['status' => 'published']);
        $attendee = Attendee::factory()->for($this->event)->create();
        $completeness = app(RegistrationCompleteness::class);
        $this->assertTrue($completeness->isComplete($attendee));

        FormSubmission::create(['form_id' => $form['id'], 'attendee_id' => $attendee->id]);
        $this->assertFalse($completeness->isComplete($attendee->fresh()));

        $this->answer(Form::find($form['id']), FormField::find($form['fields'][0]['id']), [], $attendee)->options()->attach($form['fields'][0]['options'][0]['id']);
        $this->assertTrue($completeness->isComplete($attendee->fresh()));
    }

    public function test_delete_is_free_without_answers_and_refused_with_them(): void
    {
        $free = $this->create();
        $this->deleteJson("{$this->url}/{$free['id']}")->assertNoContent();
        $this->assertDatabaseCount('forms', 0);
        $this->assertDatabaseCount('form_fields', 0);

        $form = $this->create();
        $this->answer(Form::find($form['id']), FormField::find($form['fields'][0]['id']), ['value' => 'x']);
        $this->deleteJson("{$this->url}/{$form['id']}")->assertUnprocessable();
        $this->assertDatabaseCount('forms', 1);
    }

    public function test_copy_from_another_event_in_the_organization_is_an_unlinked_draft(): void
    {
        $other = Event::factory()->for($this->org)->create();
        $source = Form::factory()->for($other)->published()->required()->create(['title' => 'Meal']);
        $field = $source->fields()->create(['type' => 'single_choice', 'label' => 'Choice', 'is_required' => true, 'sort_order' => 0]);
        $field->options()->create(['label' => 'Rice', 'sort_order' => 0]);
        $field->options()->create(['label' => 'Old', 'sort_order' => 1, 'archived_at' => now()]);
        $source->fields()->create(['type' => 'short_text', 'label' => 'Gone', 'sort_order' => 1, 'archived_at' => now()]);

        $copy = $this->postJson("{$this->url}/copy", ['source_form_id' => $source->id])->assertCreated()->json('data');

        $this->assertSame('draft', $copy['status']);
        $this->assertSame($this->event->id, $copy['event_id']);
        $this->assertNotSame($source->id, $copy['id']);
        $this->assertCount(1, $copy['fields']);
        $this->assertNotSame($field->id, $copy['fields'][0]['id']);
        $this->assertSame(['Rice'], collect($copy['fields'][0]['options'])->pluck('label')->all());
        $this->assertSame(0, Form::find($copy['id'])->submissions()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'form.copied']);
    }

    public function test_copy_from_another_organizations_event_is_refused(): void
    {
        $foreign = Form::factory()->for(Event::factory()->for(Organization::factory()->create())->create())->create();

        $this->postJson("{$this->url}/copy", ['source_form_id' => $foreign->id])
            ->assertUnprocessable()->assertJsonValidationErrors('source_form_id');
        $this->actingAs(User::factory()->superAdmin()->create(), 'sanctum');
        $this->postJson("{$this->url}/copy", ['source_form_id' => $foreign->id])->assertUnprocessable();
        $this->postJson("{$this->url}/copy", ['source_form_id' => 0])->assertUnprocessable();
        $this->assertDatabaseCount('forms', 1);
    }

    public function test_roles_and_organizations(): void
    {
        $form = $this->create();
        $show = "{$this->url}/{$form['id']}";

        $this->actingAs(User::factory()->superAdmin()->create(), 'sanctum');
        $this->getJson($show)->assertOk();
        $this->postJson($this->url, $this->payload())->assertCreated();

        foreach ([User::factory()->checker()->for($this->org)->create(), User::factory()->orgAdmin()->for(Organization::factory()->create())->create()] as $user) {
            $this->actingAs($user, 'sanctum');
            $this->getJson($this->url)->assertStatus($user->isChecker() ? 403 : 404);
            $this->postJson($this->url, $this->payload())->assertStatus($user->isChecker() ? 403 : 404);
            $this->getJson($show)->assertStatus($user->isChecker() ? 403 : 404);
            $this->putJson($show, $this->editable($form))->assertStatus($user->isChecker() ? 403 : 404);
            $this->deleteJson($show)->assertStatus($user->isChecker() ? 403 : 404);
            $this->postJson("$show/publish")->assertStatus($user->isChecker() ? 403 : 404);
            $this->putJson("{$this->url}/order", ['form_ids' => [$form['id']]])->assertStatus($user->isChecker() ? 403 : 404);
            $this->postJson("{$this->url}/copy", ['source_form_id' => $form['id']])->assertStatus($user->isChecker() ? 403 : 404);
        }
        $this->assertSame(2, Form::withoutGlobalScopes()->where('event_id', $this->event->id)->count());
    }

    public function test_a_form_is_not_reachable_through_another_event(): void
    {
        $form = $this->create();
        $other = Event::factory()->for($this->org)->create();

        $this->postJson("/api/v1/events/{$other->id}/forms/{$form['id']}/publish")->assertNotFound();
        $this->getJson("/api/v1/events/{$other->id}/forms/{$form['id']}")->assertNotFound();
    }

    public function test_omitting_is_required_on_a_field_keeps_it_and_field_counts_are_capped(): void
    {
        $form = $this->create();
        $body = $this->editable($form);
        unset($body['fields'][0]['is_required']);
        $this->putJson("{$this->url}/{$form['id']}", $body)->assertOk()->assertJsonPath('data.fields.0.is_required', true);

        $body['fields'] = array_fill(0, 101, ['type' => 'short_text', 'label' => 'x']);
        $this->putJson("{$this->url}/{$form['id']}", $body)->assertUnprocessable()->assertJsonValidationErrors('fields');
    }

    public function test_guest_is_unauthenticated(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson($this->url)->assertUnauthorized();
    }

    public function test_archived_event_blocks_writes_but_not_reads(): void
    {
        $form = $this->create();
        $this->event->forceFill(['archived_at' => now()])->save();
        $show = "{$this->url}/{$form['id']}";

        $this->getJson($show)->assertOk();
        $this->getJson($this->url)->assertOk();
        $this->postJson($this->url, $this->payload())->assertForbidden()->assertJsonPath('message', 'This event is archived.');
        $this->putJson($show, $this->editable($form))->assertForbidden();
        $this->deleteJson($show)->assertForbidden();
        $this->postJson("$show/publish")->assertForbidden();
        $this->postJson("$show/unpublish")->assertForbidden();
        $this->putJson("{$this->url}/order", ['form_ids' => [$form['id']]])->assertForbidden();
        $this->postJson("{$this->url}/copy", ['source_form_id' => $form['id']])->assertForbidden();
    }

    public function test_the_forms_migration_rolls_back(): void
    {
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_10_22_700000_create_forms_tables.php', '--realpath' => false])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('forms'));
        $this->assertFalse(Schema::hasTable('form_answer_options'));
    }
}
