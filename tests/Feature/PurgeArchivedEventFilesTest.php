<?php

namespace Tests\Feature;

use App\Enums\FormFieldType;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\Form;
use App\Models\FormAnswer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurgeArchivedEventFilesTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
    }

    /** @return array{event: Event, attendee: Attendee, answer: FormAnswer} */
    private function eventWithFiles(?\DateTimeInterface $archivedAt): array
    {
        $event = Event::factory()->for(Organization::factory())->create();
        $event->forceFill(['archived_at' => $archivedAt])->save();
        $attendee = Attendee::factory()->for($event)->create();

        $form = Form::factory()->for($event)->published()->create();
        $field = $form->fields()->create(['type' => FormFieldType::File, 'label' => 'Proof', 'is_required' => true]);
        $submission = $attendee->formSubmissions()->create(['form_id' => $form->id]);
        $path = "form-answers/{$submission->id}/{$field->id}-x.pdf";
        Storage::disk('local')->put($path, 'pdf');
        $answer = $submission->answers()->create(['form_field_id' => $field->id, 'file_path' => $path, 'file_name' => 'proof.pdf']);

        Storage::disk('local')->put("{$event->id}/{$attendee->id}.pdf", 'card');
        Storage::disk('local')->put("{$event->id}/images/{$attendee->id}-page-0.jpg", 'img');
        $attendee->forceFill([
            'id_card_path' => "{$event->id}/{$attendee->id}.pdf",
            'id_card_images_path' => ["{$event->id}/images/{$attendee->id}-page-0.jpg"],
            'id_card_generated_at' => now(),
        ])->saveQuietly();

        return compact('event', 'attendee', 'answer');
    }

    public function test_deletes_files_of_an_event_archived_16_days_ago(): void
    {
        ['event' => $event, 'attendee' => $attendee, 'answer' => $answer] = $this->eventWithFiles(now()->subDays(16));
        $photo = ['thumb' => 'attendees/1/photo.jpg'];
        $attendee->forceFill(['photo_paths' => $photo])->saveQuietly();
        Storage::disk('local')->put('attendees/1/photo.jpg', 'photo');

        $this->artisan('app:purge-archived-event-files')->assertSuccessful();

        Storage::disk('local')->assertMissing($answer->getOriginal('file_path'));
        Storage::disk('local')->assertMissing("{$event->id}/{$attendee->id}.pdf");
        Storage::disk('local')->assertMissing("{$event->id}/images/{$attendee->id}-page-0.jpg");
        Storage::disk('local')->assertExists('attendees/1/photo.jpg');

        $answer->refresh();
        $this->assertSame('proof.pdf', $answer->file_name);
        $this->assertNull($answer->file_path);
        $this->assertNotNull($answer->file_deleted_at);
        $attendee = Attendee::withoutGlobalScopes()->find($attendee->id);
        $this->assertNull($attendee->id_card_path);
        $this->assertNull($attendee->id_card_images_path);
        $this->assertNull($attendee->id_card_generated_at);
        $this->assertSame($photo, $attendee->photo_paths);
    }

    public function test_keeps_files_of_events_archived_14_days_ago_or_not_archived(): void
    {
        $recent = $this->eventWithFiles(now()->subDays(14));
        $live = $this->eventWithFiles(null);

        $this->artisan('app:purge-archived-event-files')->assertSuccessful();

        foreach ([$recent, $live] as $set) {
            $answer = $set['answer']->refresh();
            $this->assertNotNull($answer->file_path);
            $this->assertNull($answer->file_deleted_at);
            Storage::disk('local')->assertExists($answer->file_path);
            $this->assertNotNull($set['attendee']->fresh()->id_card_generated_at);
        }
    }

    public function test_unarchive_prevents_deletion_and_archiving_again_restarts_the_clock(): void
    {
        $admin = User::factory()->superAdmin()->create();
        ['event' => $event, 'answer' => $answer] = $this->eventWithFiles(now()->subDays(20));

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/events/{$event->id}/unarchive")->assertOk();
        $this->artisan('app:purge-archived-event-files')->assertSuccessful();
        $this->assertNotNull($answer->refresh()->file_path);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/events/{$event->id}/archive")->assertOk();
        $this->artisan('app:purge-archived-event-files')->assertSuccessful();
        $this->assertNotNull($answer->refresh()->file_path);

        $this->travel(15)->days();
        $this->artisan('app:purge-archived-event-files')->assertSuccessful();
        $this->assertNull($answer->refresh()->file_path);
    }

    public function test_deleted_files_still_count_as_answered_and_cannot_be_downloaded(): void
    {
        ['event' => $event, 'attendee' => $attendee, 'answer' => $answer] = $this->eventWithFiles(now()->subDays(16));
        $this->artisan('app:purge-archived-event-files')->assertSuccessful();

        $this->assertTrue($answer->refresh()->isAnswered());

        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin, 'sanctum')->get(route('form-answers.file', $answer))->assertNotFound();
    }

    public function test_every_row_is_purged_across_chunk_boundaries(): void
    {
        ['event' => $event, 'attendee' => $first, 'answer' => $answer] = $this->eventWithFiles(now()->subDays(16));
        $field = $answer->field;
        foreach (range(1, 450) as $i) {
            $attendee = Attendee::factory()->for($event)->create();
            $submission = $attendee->formSubmissions()->create(['form_id' => $field->form_id]);
            $submission->answers()->create(['form_field_id' => $field->id, 'file_path' => "form-answers/bulk/{$i}.pdf", 'file_name' => 'x.pdf']);
            $attendee->forceFill(['id_card_path' => "{$event->id}/bulk-{$i}.pdf"])->saveQuietly();
        }

        $this->artisan('app:purge-archived-event-files')->expectsOutput('Deleted 451 form files and 451 ID cards.')->assertSuccessful();

        $this->assertSame(0, FormAnswer::whereNotNull('file_path')->count());
        $this->assertSame(0, Attendee::withoutGlobalScopes()->whereNotNull('id_card_path')->count());
    }

    public function test_rerun_is_a_no_op(): void
    {
        ['answer' => $answer] = $this->eventWithFiles(now()->subDays(16));
        $this->artisan('app:purge-archived-event-files')->assertSuccessful();
        $deletedAt = $answer->refresh()->file_deleted_at;

        $this->travel(1)->day();
        $this->artisan('app:purge-archived-event-files')->expectsOutput('Deleted 0 form files and 0 ID cards.')->assertSuccessful();

        $this->assertEquals($deletedAt, $answer->refresh()->file_deleted_at);
    }

    public function test_is_scheduled_daily(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'app:purge-archived-event-files'));

        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
    }

    public function test_event_resource_exposes_the_deletion_date(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $event = Event::factory()->create();

        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/events/{$event->id}")->assertJsonPath('data.files_deletion_at', null);

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/events/{$event->id}/archive")->assertOk();
        $this->assertSame($event->fresh()->archived_at->addDays(15)->toJSON(), $response->json('data.files_deletion_at'));

        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/events/{$event->id}")
            ->assertJsonPath('data.files_deletion_at', $response->json('data.files_deletion_at'));

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/events/{$event->id}/unarchive")->assertJsonPath('data.files_deletion_at', null);
    }
}
