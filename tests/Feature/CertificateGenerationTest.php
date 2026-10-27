<?php

namespace Tests\Feature;

use App\Enums\OrganizationLevel;
use App\Jobs\GenerateCertificateJob;
use App\Jobs\GenerateIssuedCertificatesBatchJob;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use App\Services\CertificateRenderer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CertificateGenerationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $org;

    private Event $event;

    private Certificate $certificate;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->org = Organization::factory()->create();
        $this->event = Event::factory()->for($this->org)->create();
        $this->certificate = Certificate::factory()->for($this->event)->create();
        $this->actingAs(User::factory()->orgAdmin()->for($this->org)->create(), 'sanctum');
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/events/{$this->event->id}/certificates/{$this->certificate->id}".$suffix;
    }

    private function uploadTemplate(int $width = 600, int $height = 400): void
    {
        $this->postJson($this->url('/template'), ['template' => UploadedFile::fake()->image('t.png', $width, $height)])->assertOk();
    }

    private function publish(): TestResponse
    {
        return $this->postJson($this->url('/publish'));
    }

    private function download(Attendee $attendee): TestResponse
    {
        return $this->get($this->url("/attendees/{$attendee->id}/download"));
    }

    /** @return array{float, float} Page width and height in points read from the PDF itself. */
    private function pageSize(string $pdf): array
    {
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(1, preg_match('/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+([\d.]+)\s+([\d.]+)\s*\]/', $pdf, $m));

        return [(float) $m[1], (float) $m[2]];
    }

    private function assertPageSize(string $pdf, float $width, float $height): void
    {
        [$w, $h] = $this->pageSize($pdf);
        $this->assertEqualsWithDelta($width, $w, 0.5);
        $this->assertEqualsWithDelta($height, $h, 0.5);
    }

    public function test_publishing_generates_a_pdf_for_every_audience_attendee_with_progress_counts(): void
    {
        $this->uploadTemplate();
        $attendees = Attendee::factory()->count(3)->for($this->event)->create();
        // Not Fully registered, and of another event: neither is in the Audience.
        Attendee::factory()->for($this->event)->draft()->create();
        Attendee::factory()->for(Event::factory()->for($this->org)->create())->create();

        $this->publish()
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.generation.total', 3)
            ->assertJsonPath('data.generation.generated', 3)
            ->assertJsonPath('data.generation.failed', 0)
            ->assertJsonPath('data.generation.name_too_long', 0);

        $this->getJson($this->url())->assertJsonPath('data.generation.generated', 3);
        foreach ($attendees as $attendee) {
            $this->assertPageSize($this->download($attendee)->assertOk()->streamedContent(), 841.89, 595.28);
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'certificate.published', 'auditable_id' => $this->certificate->id]);
    }

    public function test_attendees_who_are_not_yet_eligible_also_get_a_file(): void
    {
        $this->uploadTemplate();
        $this->certificate->update(['release_rule' => 'rate_75', 'release_at' => now()->addYear()]);
        $attendee = Attendee::factory()->for($this->event)->create();

        $this->publish()->assertOk()->assertJsonPath('data.generation.generated', 1);

        $this->download($attendee)->assertOk();
    }

    public function test_audience_selection_limits_who_gets_a_file(): void
    {
        $this->uploadTemplate();
        $union = Union::factory()->create(['organization_id' => $this->org->id]);
        $inUnion = Attendee::factory()->for($this->event)->create(['organization_level' => OrganizationLevel::Union, 'union_id' => $union->id]);
        $other = Attendee::factory()->for($this->event)->create();
        $this->certificate->update(['audience_union_ids' => [$union->id]]);

        $this->publish()->assertJsonPath('data.generation.total', 1);

        $this->download($inUnion)->assertOk();
        $this->download($other)->assertNotFound();
    }

    public function test_page_size_and_orientation_come_from_the_setup(): void
    {
        $this->uploadTemplate();
        $attendee = Attendee::factory()->for($this->event)->create();

        foreach ([
            ['a4', 'portrait', 595.28, 841.89],
            ['short', 'landscape', 792.0, 612.0],
            ['short', 'portrait', 612.0, 792.0],
            ['long', 'portrait', 612.0, 936.0],
        ] as [$size, $orientation, $width, $height]) {
            $this->postJson($this->url('/unpublish'))->assertOk();
            $this->patchJson($this->url(), ['page_size' => $size, 'orientation' => $orientation])->assertOk();
            $this->publish()->assertOk();

            $this->assertPageSize($this->download($attendee)->streamedContent(), $width, $height);
        }
    }

    public function test_publishing_needs_a_template(): void
    {
        Attendee::factory()->for($this->event)->create();

        $this->publish()->assertUnprocessable()->assertJsonValidationErrors('template');

        $this->assertSame('draft', $this->certificate->fresh()->status->value);
    }

    public function test_publishing_twice_is_rejected(): void
    {
        $this->uploadTemplate();
        $this->publish()->assertOk();

        $this->publish()->assertUnprocessable();
    }

    public function test_design_is_locked_while_published_but_release_settings_stay_editable(): void
    {
        $this->uploadTemplate();
        $this->publish()->assertOk();

        foreach ([
            ['name_x' => 0.4], ['name_y' => 0.4], ['name_font_size' => 0.08], ['font' => 'serif'],
            ['color' => '#ff0000'], ['page_size' => 'long'], ['orientation' => 'portrait'],
        ] as $change) {
            $this->patchJson($this->url(), $change)->assertUnprocessable()->assertJsonValidationErrors(array_key_first($change));
        }
        $this->postJson($this->url('/template'), ['template' => UploadedFile::fake()->image('n.png')])->assertUnprocessable();
        $this->postJson($this->url('/google-font'), ['url' => 'https://fonts.google.com/specimen/Lobster'])->assertUnprocessable();

        // Sending the current design value back unchanged is not a change.
        $this->patchJson($this->url(), ['color' => '#000000', 'title' => 'Renamed'])->assertOk();
        $this->patchJson($this->url(), [
            'release_rule' => 'checked_in', 'release_at' => '2030-01-01T10:00:00Z',
            'include_breakouts' => true, 'audience' => ['union_ids' => []],
        ])->assertOk()->assertJsonPath('data.release_rule', 'checked_in');
    }

    public function test_unpublish_then_publish_regenerates_every_file(): void
    {
        $this->uploadTemplate();
        $attendee = Attendee::factory()->for($this->event)->create();
        $this->publish()->assertOk();
        $disk = Storage::disk('local');
        $path = $disk->allFiles('events')[0];
        $disk->put($path, 'stale');

        $this->postJson($this->url('/unpublish'))->assertOk()->assertJsonPath('data.status', 'draft');
        $this->patchJson($this->url(), ['color' => '#ff0000'])->assertOk();
        $this->publish()->assertOk()->assertJsonPath('data.generation.generated', 1);

        $this->assertStringStartsWith('%PDF-', $this->download($attendee)->streamedContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'certificate.unpublished', 'auditable_id' => $this->certificate->id]);
    }

    public function test_a_long_name_shrinks_to_fit_and_a_hopeless_one_is_flagged(): void
    {
        $this->uploadTemplate();
        $fits = Attendee::factory()->for($this->event)->create(['first_name' => str_repeat('Wolfeschlegel', 3), 'last_name' => 'Smith']);
        $hopeless = Attendee::factory()->for($this->event)->create(['first_name' => str_repeat('W', 150), 'last_name' => 'Smith']);
        $normal = Attendee::factory()->for($this->event)->create(['first_name' => 'Ann', 'last_name' => 'Lee']);
        $this->patchJson($this->url(), ['name_font_size' => 0.08])->assertOk();

        $this->publish()
            ->assertJsonPath('data.generation.generated', 3)
            ->assertJsonPath('data.generation.name_too_long', 1);

        // The flagged one still gets its file, at the floor size.
        foreach ([$fits, $hopeless, $normal] as $attendee) {
            $this->download($attendee)->assertOk();
        }
    }

    public function test_a_failing_render_is_recorded_with_its_reason_without_stopping_the_run(): void
    {
        $this->uploadTemplate();
        Attendee::factory()->count(2)->for($this->event)->create();
        Storage::disk('public')->delete($this->certificate->fresh()->template_path);

        $this->publish()
            ->assertOk()
            ->assertJsonPath('data.generation.total', 2)
            ->assertJsonPath('data.generation.generated', 0)
            ->assertJsonPath('data.generation.failed', 2);
        $this->assertDatabaseHas('issued_certificates', ['status' => 'failed', 'failure_reason' => 'The Template image is missing.']);
    }

    public function test_publishing_is_blocked_on_an_archived_event(): void
    {
        $this->uploadTemplate();
        $this->event->forceFill(['archived_at' => now()])->save();

        $this->publish()->assertForbidden();
        $this->postJson($this->url('/unpublish'))->assertForbidden();

        $this->assertSame('draft', $this->certificate->fresh()->status->value);
    }

    public function test_the_job_exits_quietly_when_the_event_is_archived_mid_run(): void
    {
        $this->uploadTemplate();
        $attendee = Attendee::factory()->for($this->event)->create();
        Queue::fake();
        $this->publish()->assertOk();
        Queue::assertPushed(GenerateCertificateJob::class);

        $this->event->forceFill(['archived_at' => now()])->save();
        (new GenerateCertificateJob($this->certificate->id, $this->certificate->fresh()->generation_run))->handle();

        $this->assertSame([], Storage::disk('local')->allFiles('events'));
        $this->assertDatabaseHas('issued_certificates', ['attendee_id' => $attendee->id, 'status' => 'pending']);
    }

    public function test_the_batch_job_writes_nothing_when_the_event_is_archived(): void
    {
        $this->uploadTemplate();
        $attendee = Attendee::factory()->for($this->event)->create();
        Queue::fake();
        $this->publish()->assertOk();
        $issued = $this->certificate->issuedCertificates()->firstOrFail();

        $this->event->forceFill(['archived_at' => now()])->save();
        (new GenerateIssuedCertificatesBatchJob($this->certificate->id, $this->certificate->fresh()->generation_run, [$issued->id]))
            ->handle(app(CertificateRenderer::class));

        $this->assertSame([], Storage::disk('local')->allFiles('events'));
        $this->assertDatabaseHas('issued_certificates', ['attendee_id' => $attendee->id, 'status' => 'pending']);
    }

    public function test_a_run_left_behind_by_unpublishing_does_not_generate(): void
    {
        $this->uploadTemplate();
        Attendee::factory()->for($this->event)->create();
        Queue::fake();
        $this->publish()->assertOk();
        $stamp = $this->certificate->fresh()->generation_run;

        $this->postJson($this->url('/unpublish'))->assertOk();
        (new GenerateCertificateJob($this->certificate->id, $stamp))->handle();

        $this->assertSame([], Storage::disk('local')->allFiles('events'));
    }

    public function test_download_is_admin_only_and_organization_scoped(): void
    {
        $this->uploadTemplate();
        $attendee = Attendee::factory()->for($this->event)->create();
        $this->publish()->assertOk();

        $this->actingAs(User::factory()->checker()->for($this->org)->create(), 'sanctum');
        $this->download($attendee)->assertForbidden();

        $this->actingAs(User::factory()->orgAdmin()->for(Organization::factory()->create())->create(), 'sanctum');
        $this->download($attendee)->assertNotFound();
    }

    public function test_download_of_an_attendee_without_a_file_is_not_found(): void
    {
        $this->uploadTemplate();
        $attendee = Attendee::factory()->for($this->event)->create();

        $this->download($attendee)->assertNotFound();
    }

    public function test_publish_is_recorded_in_the_audit_log_with_the_audience_size(): void
    {
        $this->uploadTemplate();
        Attendee::factory()->count(2)->for($this->event)->create();

        $this->publish()->assertOk();

        $log = AuditLog::where('action', 'certificate.published')->firstOrFail();
        $this->assertSame(2, $log->changes['audience'] ?? null);
    }
}
