<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Certificate;
use App\Models\Event;
use App\Models\IssuedCertificate;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CertificateLifecycleTest extends TestCase
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

    private function publishWith(int $attendees): void
    {
        $this->postJson($this->url('/template'), ['template' => UploadedFile::fake()->image('t.png', 600, 400)])->assertOk();
        Attendee::factory()->count($attendees)->for($this->event)->create();
        $this->postJson($this->url('/publish'))->assertOk();
    }

    public function test_a_published_certificate_cannot_be_deleted(): void
    {
        $this->publishWith(1);

        $this->deleteJson($this->url())->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('certificates', ['id' => $this->certificate->id]);
    }

    public function test_a_draft_and_an_unpublished_certificate_can_be_deleted(): void
    {
        $this->deleteJson($this->url())->assertNoContent();

        $this->certificate = Certificate::factory()->for($this->event)->create();
        $this->publishWith(1);
        $this->postJson($this->url('/unpublish'))->assertOk();
        $this->deleteJson($this->url())->assertNoContent();
        $this->assertDatabaseMissing('certificates', ['id' => $this->certificate->id]);
    }

    public function test_regenerate_missing_restores_purged_files_after_unarchive(): void
    {
        $this->publishWith(3);
        $rows = IssuedCertificate::orderBy('id')->get();
        IssuedCertificate::whereKey($rows[2]->id)->update(['hidden_at' => now()]);
        $this->event->forceFill(['archived_at' => now()->subDays(30)])->save();
        $this->artisan('app:purge-archived-event-files')->assertSuccessful();
        $this->assertSame([], Storage::disk('local')->allFiles("events/{$this->event->id}/certificates"));
        $this->event->forceFill(['archived_at' => null])->save();
        $this->assertNotNull($this->getJson($this->url())->json('data.generation.files_deleted_at'));

        $this->postJson($this->url('/regenerate-missing'))->assertOk()->assertJsonPath('data.requeued', 2);

        $this->getJson($this->url())
            ->assertJsonPath('data.generation.generated', 2)
            ->assertJsonPath('data.generation.files_deleted_at', null);
        $this->assertTrue(Storage::disk('local')->exists($rows[0]->fresh()->file_path));
        $this->assertNull($rows[2]->fresh()->file_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'certificate.regenerated_missing', 'auditable_id' => $this->certificate->id]);
    }

    public function test_regenerate_missing_also_requeues_failed_and_vanished_files_but_not_good_ones(): void
    {
        $this->publishWith(3);
        $rows = IssuedCertificate::orderBy('id')->get();
        $stamp = $rows[0]->generated_at;
        IssuedCertificate::whereKey($rows[1]->id)->update(['status' => 'failed', 'failure_reason' => 'Boom', 'file_path' => null]);
        Storage::disk('local')->delete($rows[2]->file_path);
        $this->travel(5)->minutes();

        $this->postJson($this->url('/regenerate-missing'))->assertOk()->assertJsonPath('data.requeued', 2);

        $this->getJson($this->url())->assertJsonPath('data.generation.generated', 3)->assertJsonPath('data.generation.failed', 0);
        $this->assertEquals($stamp, $rows[0]->fresh()->generated_at);
    }

    public function test_regenerate_missing_needs_a_published_certificate(): void
    {
        $this->postJson($this->url('/regenerate-missing'))->assertUnprocessable();
    }

    public function test_regenerate_missing_is_blocked_on_an_archived_event(): void
    {
        $this->publishWith(1);
        $this->event->forceFill(['archived_at' => now()])->save();

        $this->postJson($this->url('/regenerate-missing'))->assertForbidden();
    }
}
