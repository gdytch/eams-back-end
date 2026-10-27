<?php

namespace Tests\Feature;

use App\Enums\FormFieldType;
use App\Jobs\GenerateIssuedCertificatesBatchJob;
use App\Models\Attendee;
use App\Models\Certificate;
use App\Models\Event;
use App\Models\Form;
use App\Models\FormAnswer;
use App\Models\IssuedCertificate;
use App\Models\Organization;
use App\Models\User;
use App\Services\CertificateRenderer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    private function issuedCertificate(Event $event, string $name = 'a'): IssuedCertificate
    {
        $certificate = Certificate::query()->where('event_id', $event->id)->first()
            ?? Certificate::factory()->for($event)->create(['template_path' => 'tpl.png', 'status' => 'published', 'generation_run' => 1]);
        $attendee = Attendee::factory()->for($event)->create();
        $path = "events/{$event->id}/certificates/{$certificate->id}/{$name}-{$attendee->id}.pdf";
        Storage::disk('local')->put($path, 'pdf');

        return IssuedCertificate::create([
            'certificate_id' => $certificate->id, 'attendee_id' => $attendee->id,
            'status' => 'generated', 'file_path' => $path, 'generated_at' => now(),
        ]);
    }

    public function test_deletes_issued_certificate_files_after_retention_but_keeps_rows_template_and_font(): void
    {
        $event = $this->eventWithFiles(now()->subDays(16))['event'];
        $issued = $this->issuedCertificate($event);
        $path = $issued->file_path;
        $certificate = $issued->certificate;
        Storage::disk('local')->put('fonts/google/x.ttf', 'font');
        $certificate->forceFill(['google_font_path' => 'fonts/google/x.ttf'])->save();

        $this->artisan('app:purge-archived-event-files')->assertSuccessful();

        Storage::disk('local')->assertMissing($path);
        Storage::disk('local')->assertExists('fonts/google/x.ttf');
        $issued->refresh();
        $this->assertNull($issued->file_path);
        $this->assertNotNull($issued->file_deleted_at);
        $this->assertSame('generated', $issued->status->value);
        $this->assertSame('tpl.png', $certificate->fresh()->template_path);
        $this->assertSame('fonts/google/x.ttf', $certificate->fresh()->google_font_path);
    }

    public function test_keeps_issued_certificate_files_of_recently_archived_unarchived_or_live_events(): void
    {
        $recent = $this->issuedCertificate($this->eventWithFiles(now()->subDays(14))['event']);
        $live = $this->issuedCertificate($this->eventWithFiles(null)['event']);
        $unarchived = $this->eventWithFiles(now()->subDays(20))['event'];
        $kept = $this->issuedCertificate($unarchived);
        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/events/{$unarchived->id}/unarchive")->assertOk();

        $this->artisan('app:purge-archived-event-files')->assertSuccessful();

        foreach ([$recent, $live, $kept] as $issued) {
            $this->assertNotNull($issued->refresh()->file_path);
            $this->assertNull($issued->file_deleted_at);
            Storage::disk('local')->assertExists($issued->file_path);
        }
    }

    public function test_purged_issued_certificates_are_not_downloadable_and_regenerate_after_unarchive(): void
    {
        $event = $this->eventWithFiles(now()->subDays(16))['event'];
        $issued = $this->issuedCertificate($event);
        $certificate = $issued->certificate;
        $this->artisan('app:purge-archived-event-files')->assertSuccessful();
        $deletedAt = $issued->refresh()->file_deleted_at;

        $admin = User::factory()->superAdmin()->create();
        $base = "/api/v1/events/{$event->id}/certificates/{$certificate->id}";
        $this->actingAs($admin, 'sanctum')->get("{$base}/attendees/{$issued->attendee_id}/download")->assertStatus(410);
        $this->actingAs($admin, 'sanctum')->getJson($base)->assertJsonPath('data.generation.files_deleted_at', $deletedAt->toJSON());

        $this->postJson("/api/v1/events/{$event->id}/unarchive")->assertOk();
        $this->postJson("{$base}/unpublish")->assertOk();
        $this->postJson("{$base}/publish")->assertOk();
        // The queue is faked here, so run the generation batch the publish queued.
        Storage::fake('public');
        $image = UploadedFile::fake()->image('t.png', 600, 400);
        Storage::disk('public')->put('tpl.png', $image->get());
        $issued->refresh();
        $this->assertSame('pending', $issued->status->value);
        (new GenerateIssuedCertificatesBatchJob($certificate->id, $certificate->fresh()->generation_run, [$issued->id]))->handle(app(CertificateRenderer::class));
        $this->getJson($base)->assertJsonPath('data.generation.files_deleted_at', null);

        $issued->refresh();
        $this->assertSame('generated', $issued->status->value);
        $this->assertNull($issued->file_deleted_at);
        Storage::disk('local')->assertExists($issued->file_path);
        $this->actingAs($admin, 'sanctum')->get("{$base}/attendees/{$issued->attendee_id}/download")->assertOk();
    }

    public function test_every_issued_certificate_is_purged_across_chunk_boundaries(): void
    {
        $event = $this->eventWithFiles(now()->subDays(16))['event'];
        // One Attendee with many Certificates: the Attendee factory cannot make 450 unique rows.
        $attendee = Attendee::factory()->for($event)->create();
        foreach (range(1, 450) as $i) {
            $certificate = Certificate::factory()->for($event)->create(['template_path' => 'tpl.png']);
            IssuedCertificate::create([
                'certificate_id' => $certificate->id, 'attendee_id' => $attendee->id,
                'status' => 'generated', 'file_path' => "events/{$event->id}/certificates/{$certificate->id}/bulk.pdf", 'generated_at' => now(),
            ]);
        }

        $this->artisan('app:purge-archived-event-files')->expectsOutput('Deleted 450 issued certificate files.')->assertSuccessful();

        $this->assertSame(0, IssuedCertificate::whereNotNull('file_path')->count());
        $this->assertSame(450, IssuedCertificate::whereNotNull('file_deleted_at')->count());
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
