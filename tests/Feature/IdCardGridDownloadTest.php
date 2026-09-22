<?php

namespace Tests\Feature;

use App\Enums\IdCardGridDownloadStatus;
use App\Jobs\GenerateIdCardGridBatchJob;
use App\Jobs\GenerateIdCardGridDownloadJob;
use App\Jobs\MergeIdCardGridDownloadJob;
use App\Jobs\PrepareIdCardGridDownloadJob;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\IdCardGridDownload;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class IdCardGridDownloadTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_start_grid_download_with_specific_registrations_queues_job(): void
    {
        Queue::fake();
        Storage::fake('local');

        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $reg1 = EventRegistration::factory()->for($event)->for(Attendee::factory()->for($org))->create();
        $reg2 = EventRegistration::factory()->for($event)->for(Attendee::factory()->for($org))->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')->postJson(
            "/api/v1/events/{$event->id}/registrations/id-cards/grid-download",
            ['registration_ids' => [$reg1->id, $reg2->id]]
        );

        $response->assertStatus(202);
        $response->assertJsonStructure(['data' => ['id', 'event_id', 'status', 'registration_ids']]);
        $this->assertEquals('pending', $response->json('data.status'));
        $this->assertEquals([$reg1->id, $reg2->id], $response->json('data.registration_ids'));

        Queue::assertPushed(PrepareIdCardGridDownloadJob::class);

        $download = IdCardGridDownload::first();
        $this->assertNotNull($download);
        $this->assertEquals($event->id, $download->event_id);
        $this->assertEquals($orgAdmin->id, $download->requested_by);
        $this->assertEquals([$reg1->id, $reg2->id], $download->registration_ids);
    }

    public function test_start_grid_download_all_queues_job(): void
    {
        Queue::fake();

        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        EventRegistration::factory()->for($event)->for(Attendee::factory()->for($org))->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')->postJson(
            "/api/v1/events/{$event->id}/registrations/id-cards/grid-download-all"
        );

        $response->assertStatus(202);
        $response->assertJsonStructure(['data' => ['id', 'event_id', 'status']]);
        $this->assertNull($response->json('data.registration_ids'));

        Queue::assertPushed(PrepareIdCardGridDownloadJob::class);

        $download = IdCardGridDownload::first();
        $this->assertNull($download->registration_ids);
    }

    public function test_start_grid_download_requires_org_admin(): void
    {
        Queue::fake();

        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $reg = EventRegistration::factory()->for($event)->for(Attendee::factory()->for($org))->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson(
            "/api/v1/events/{$event->id}/registrations/id-cards/grid-download",
            ['registration_ids' => [$reg->id]]
        );

        $response->assertForbidden();
        Queue::assertNotPushed(PrepareIdCardGridDownloadJob::class);
    }

    public function test_start_grid_download_with_nonexistent_registration_ids_returns_404(): void
    {
        Queue::fake();

        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')->postJson(
            "/api/v1/events/{$event->id}/registrations/id-cards/grid-download",
            ['registration_ids' => [9999]]
        );

        $response->assertNotFound();
        Queue::assertNotPushed(PrepareIdCardGridDownloadJob::class);
    }

    public function test_start_grid_download_all_with_no_registrations_returns_404(): void
    {
        Queue::fake();

        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')->postJson(
            "/api/v1/events/{$event->id}/registrations/id-cards/grid-download-all"
        );

        $response->assertNotFound();
        Queue::assertNotPushed(PrepareIdCardGridDownloadJob::class);
    }

    public function test_show_grid_download_returns_current_status(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $download = IdCardGridDownload::factory()->for($event)->create([
            'status' => IdCardGridDownloadStatus::Processing,
        ]);

        $response = $this->actingAs($orgAdmin, 'sanctum')->getJson(
            "/api/v1/events/{$event->id}/registrations/id-cards/grid-download/{$download->id}"
        );

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['id', 'event_id', 'status']]);
        $this->assertEquals('processing', $response->json('data.status'));
    }

    public function test_show_grid_download_from_different_org_returns_404(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        $user = User::factory()->orgAdmin()->for($org2)->create();
        $event = Event::factory()->for($org1)->create();
        $download = IdCardGridDownload::factory()->for($event)->create();

        $response = $this->actingAs($user, 'sanctum')->getJson(
            "/api/v1/events/{$event->id}/registrations/id-cards/grid-download/{$download->id}"
        );

        $response->assertNotFound();
    }

    public function test_download_grid_file_returns_202_while_processing(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $download = IdCardGridDownload::factory()->for($event)->create([
            'status' => IdCardGridDownloadStatus::Processing,
        ]);

        $response = $this->actingAs($orgAdmin, 'sanctum')->getJson(
            "/api/v1/events/{$event->id}/registrations/id-cards/grid-download/{$download->id}/file"
        );

        $response->assertStatus(202);
        $response->assertJson([
            'message' => 'Grid ZIP is still being generated. Check back shortly.',
            'status' => 'processing',
        ]);
    }

    public function test_download_grid_file_returns_422_when_failed(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $download = IdCardGridDownload::factory()->failed()->for($event)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')->getJson(
            "/api/v1/events/{$event->id}/registrations/id-cards/grid-download/{$download->id}/file"
        );

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'No ID cards were ready to include in this batch.',
        ]);
    }

    public function test_download_grid_file_returns_zip_when_completed(): void
    {
        Storage::fake('local');

        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $download = IdCardGridDownload::factory()->completed()->for($event)->create([
            'file_path' => 'test-grid.zip',
        ]);

        Storage::disk('local')->put('test-grid.zip', 'fake zip content');

        $response = $this->actingAs($orgAdmin, 'sanctum')->getJson(
            "/api/v1/events/{$event->id}/registrations/id-cards/grid-download/{$download->id}/file"
        );

        $response->assertOk();
        $response->assertHeader('content-type', 'application/zip');
        $response->assertHeader('content-length', (string) strlen('fake zip content'));
        $response->assertDownload("event-{$event->id}-id-card-grid-batches.zip");
        $this->assertSame('fake zip content', $response->streamedContent());
        Storage::disk('local')->assertMissing('test-grid.zip');
    }

    public function test_download_grid_file_returns_404_after_the_zip_has_been_deleted(): void
    {
        Storage::fake('local');

        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $download = IdCardGridDownload::factory()->completed()->for($event)->create([
            'file_path' => 'deleted-grid.zip',
        ]);

        $response = $this->actingAs($orgAdmin, 'sanctum')->getJson(
            "/api/v1/events/{$event->id}/registrations/id-cards/grid-download/{$download->id}/file"
        );

        $response->assertNotFound();
        $response->assertJson([
            'message' => 'This ZIP download is no longer available.',
        ]);
    }

    public function test_job_generates_pdf_and_completes_successfully(): void
    {
        Storage::fake('local');

        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->create();
        $reg1 = EventRegistration::factory()->for($event)->for(Attendee::factory()->for($org))->create();
        $reg2 = EventRegistration::factory()->for($event)->for(Attendee::factory()->for($org))->create();

        // Simulate image files
        $imagePath1 = "{$event->id}/images/image1.jpg";
        $imagePath2 = "{$event->id}/images/image2.jpg";
        Storage::disk('local')->put($imagePath1, 'fake image 1');
        Storage::disk('local')->put($imagePath2, 'fake image 2');

        // Update registrations to have images
        $reg1->update(['id_card_images_path' => [$imagePath1]]);
        $reg2->update(['id_card_images_path' => [$imagePath2]]);

        $download = IdCardGridDownload::factory()->for($event)->create([
            'registration_ids' => [$reg1->id, $reg2->id],
            'status' => IdCardGridDownloadStatus::Pending,
        ]);

        (new GenerateIdCardGridDownloadJob($download))->handle();

        $download->refresh();

        $this->assertEquals(IdCardGridDownloadStatus::Completed, $download->status);
        $this->assertNotNull($download->file_path);
        $this->assertNotNull($download->completed_at);
        $this->assertNull($download->failure_reason);
        Storage::disk('local')->assertExists($download->file_path);
    }

    public function test_batch_job_tracks_completed_batches(): void
    {
        Storage::fake('local');

        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->create();
        $registration = EventRegistration::factory()
            ->for($event)
            ->for(Attendee::factory()->for($org))
            ->create();

        $imagePath = "{$event->id}/images/image.jpg";
        Storage::disk('local')->put($imagePath, 'fake image');
        $registration->update(['id_card_images_path' => [$imagePath]]);

        $download = IdCardGridDownload::factory()->for($event)->create([
            'total_batches' => 1,
        ]);

        (new GenerateIdCardGridBatchJob(
            $download->id,
            1,
            [$registration->id],
        ))->handle();

        $download->refresh();

        $this->assertSame(1, $download->completed_batches);
        $this->assertSame(90, $download->progress_percentage);
        Storage::disk('local')->assertExists(
            "{$event->id}/id-card-grids/{$download->id}/batches/batch-1.pdf"
        );
    }

    public function test_prepare_job_only_batches_the_selected_registrations(): void
    {
        Bus::fake();

        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $selectedRegistration = EventRegistration::factory()
            ->for($event)
            ->for(Attendee::factory()->for($organization))
            ->create(['id_card_images_path' => ['selected.jpg']]);
        $unselectedRegistration = EventRegistration::factory()
            ->for($event)
            ->for(Attendee::factory()->for($organization))
            ->create(['id_card_images_path' => ['unselected.jpg']]);
        $download = IdCardGridDownload::factory()->for($event)->create([
            'registration_ids' => [$selectedRegistration->id],
            'status' => IdCardGridDownloadStatus::Pending,
        ]);

        (new PrepareIdCardGridDownloadJob($download->id))->handle();

        $download->refresh();

        $this->assertSame(1, $download->total_batches);
        Bus::assertBatched(function ($batch) use ($selectedRegistration, $unselectedRegistration): bool {
            $jobs = $batch->jobs;

            return $jobs->count() === 1
                && $jobs->first() instanceof GenerateIdCardGridBatchJob
                && $jobs->first()->registrationIds === [$selectedRegistration->id]
                && ! in_array($unselectedRegistration->id, $jobs->first()->registrationIds, true);
        });
    }

    public function test_finalization_job_archives_batch_pdfs(): void
    {
        Storage::fake('local');

        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $download = IdCardGridDownload::factory()->for($event)->create([
            'status' => IdCardGridDownloadStatus::Processing,
            'progress_percentage' => 90,
            'total_batches' => 2,
            'completed_batches' => 2,
        ]);

        $batchDirectory = "{$event->id}/id-card-grids/{$download->id}/batches";
        $batchPaths = [
            "{$batchDirectory}/batch-1.pdf",
            "{$batchDirectory}/batch-2.pdf",
        ];
        $finalPath = "{$event->id}/id-card-grids/{$download->id}/id-card-grid-batches.zip";

        foreach ($batchPaths as $batchPath) {
            Storage::disk('local')->put($batchPath, '%PDF batch');
        }

        (new MergeIdCardGridDownloadJob($download->id))->handle();

        $download->refresh();

        $this->assertSame(IdCardGridDownloadStatus::Completed, $download->status);
        $this->assertSame(100, $download->progress_percentage);
        $this->assertSame($finalPath, $download->file_path);
        Storage::disk('local')->assertExists($finalPath);
        Storage::disk('local')->assertMissing($batchPaths);

        $archive = new ZipArchive;
        $this->assertTrue($archive->open(Storage::disk('local')->path($finalPath)));
        $this->assertSame('%PDF batch', $archive->getFromName('batch-1.pdf'));
        $this->assertSame('%PDF batch', $archive->getFromName('batch-2.pdf'));
        $this->assertSame(ZipArchive::CM_STORE, $archive->statName('batch-1.pdf')['comp_method']);
        $this->assertTrue($archive->close());
    }

    public function test_merge_job_marks_download_failed_after_permanent_failure(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $download = IdCardGridDownload::factory()->for($event)->create([
            'status' => IdCardGridDownloadStatus::Processing,
            'progress_percentage' => 95,
        ]);
        $job = new MergeIdCardGridDownloadJob($download->id);

        $job->failed(new \RuntimeException('Archive process timed out.'));

        $download->refresh();

        $this->assertTrue($job->failOnTimeout);
        $this->assertSame(600, $job->timeout);
        $this->assertSame(IdCardGridDownloadStatus::Failed, $download->status);
        $this->assertSame('Archive process timed out.', $download->failure_reason);
    }

    public function test_job_marks_failed_when_no_ready_images(): void
    {
        Storage::fake('local');

        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->create();
        $reg = EventRegistration::factory()->for($event)->for(Attendee::factory()->for($org))->create();

        $download = IdCardGridDownload::factory()->for($event)->create([
            'registration_ids' => [$reg->id],
            'status' => IdCardGridDownloadStatus::Pending,
        ]);

        (new GenerateIdCardGridDownloadJob($download))->handle();

        $download->refresh();

        $this->assertEquals(IdCardGridDownloadStatus::Failed, $download->status);
        $this->assertStringContainsString('No ID cards', $download->failure_reason);
        $this->assertNotNull($download->completed_at);
        $this->assertNull($download->file_path);
    }

    public function test_job_handles_all_registrations_when_ids_are_null(): void
    {
        Storage::fake('local');

        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->create();
        $reg = EventRegistration::factory()->for($event)->for(Attendee::factory()->for($org))->create();

        $imagePath = "{$event->id}/images/image.jpg";
        Storage::disk('local')->put($imagePath, 'fake image');
        $reg->update(['id_card_images_path' => [$imagePath]]);

        $download = IdCardGridDownload::factory()->for($event)->create([
            'registration_ids' => null,
            'status' => IdCardGridDownloadStatus::Pending,
        ]);

        (new GenerateIdCardGridDownloadJob($download))->handle();

        $download->refresh();

        $this->assertEquals(IdCardGridDownloadStatus::Completed, $download->status);
        Storage::disk('local')->assertExists($download->file_path);
    }

    public function test_job_updates_progress_percentage_during_processing(): void
    {
        Storage::fake('local');

        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->create();
        $reg1 = EventRegistration::factory()->for($event)->for(Attendee::factory()->for($org))->create();
        $reg2 = EventRegistration::factory()->for($event)->for(Attendee::factory()->for($org))->create();

        // Simulate image files
        $imagePath1 = "{$event->id}/images/image1.jpg";
        $imagePath2 = "{$event->id}/images/image2.jpg";
        Storage::disk('local')->put($imagePath1, 'fake image 1');
        Storage::disk('local')->put($imagePath2, 'fake image 2');

        $reg1->update(['id_card_images_path' => [$imagePath1]]);
        $reg2->update(['id_card_images_path' => [$imagePath2]]);

        $download = IdCardGridDownload::factory()->for($event)->create([
            'registration_ids' => [$reg1->id, $reg2->id],
            'status' => IdCardGridDownloadStatus::Pending,
            'progress_percentage' => 0,
        ]);

        (new GenerateIdCardGridDownloadJob($download))->handle();

        $download->refresh();

        $this->assertEquals(IdCardGridDownloadStatus::Completed, $download->status);
        $this->assertEquals(100, $download->progress_percentage);
    }

    public function test_job_resets_progress_percentage_on_failure(): void
    {
        Storage::fake('local');

        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->create();
        $reg = EventRegistration::factory()->for($event)->for(Attendee::factory()->for($org))->create();

        $download = IdCardGridDownload::factory()->for($event)->create([
            'registration_ids' => [$reg->id],
            'status' => IdCardGridDownloadStatus::Pending,
            'progress_percentage' => 0,
        ]);

        (new GenerateIdCardGridDownloadJob($download))->handle();

        $download->refresh();

        $this->assertEquals(IdCardGridDownloadStatus::Failed, $download->status);
        $this->assertEquals(0, $download->progress_percentage);
    }

    public function test_progress_percentage_returned_in_api_response(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $download = IdCardGridDownload::factory()->for($event)->create([
            'status' => IdCardGridDownloadStatus::Processing,
            'progress_percentage' => 50,
        ]);

        $response = $this->actingAs($orgAdmin, 'sanctum')->getJson(
            "/api/v1/events/{$event->id}/registrations/id-cards/grid-download/{$download->id}"
        );

        $response->assertOk();
        $response->assertJsonPath('data.progress_percentage', 50);
    }
}
