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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class CertificateAdminRunTest extends TestCase
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

    /** @return Collection<int, Attendee> */
    private function publishWith(int $attendees): Collection
    {
        $this->postJson($this->url('/template'), ['template' => UploadedFile::fake()->image('t.png', 600, 400)])->assertOk();
        $people = Attendee::factory()->count($attendees)->for($this->event)->create();
        $this->postJson($this->url('/publish'))->assertOk();

        return $people->values();
    }

    public function test_attendees_lists_rows_with_file_state_and_filters_by_search(): void
    {
        $people = $this->publishWith(3);
        $people[0]->update(['first_name' => 'Zebulon']);

        $this->getJson($this->url('/attendees'))
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['attendee_id', 'full_name', 'email_address', 'status', 'name_too_long', 'has_file']], 'meta']);

        $this->getJson($this->url('/attendees?search=Zebulon'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.attendee_id', $people[0]->id);
    }

    public function test_status_reports_counts_failures_and_the_name_too_long_list_with_identity(): void
    {
        $people = $this->publishWith(3);
        $long = $people[0];
        $failed = $people[1];
        IssuedCertificate::where('attendee_id', $long->id)->update(['name_too_long' => true]);
        IssuedCertificate::where('attendee_id', $failed->id)->update(['status' => 'failed', 'failure_reason' => 'Boom', 'file_path' => null]);

        $this->getJson($this->url('/status'))
            ->assertOk()
            ->assertJsonPath('data.generated', 2)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.failed', 1)
            ->assertJsonPath('data.name_too_long', 1)
            ->assertJsonPath('data.name_too_long_attendees.0.attendee_id', $long->id)
            ->assertJsonPath('data.name_too_long_attendees.0.full_name', $long->full_name)
            ->assertJsonPath('data.failures.0.attendee_id', $failed->id)
            ->assertJsonPath('data.failures.0.reason', 'Boom');
    }

    public function test_retry_requeues_only_failed_files(): void
    {
        $people = $this->publishWith(2);
        $failed = $people[0];
        $ok = $people[1];
        $okStamp = IssuedCertificate::where('attendee_id', $ok->id)->value('generated_at');
        IssuedCertificate::where('attendee_id', $failed->id)->update(['status' => 'failed', 'failure_reason' => 'Boom', 'file_path' => null]);
        $this->travel(5)->minutes();

        $this->postJson($this->url('/retry-failed'))->assertOk()->assertJsonPath('data.retried', 1);

        $this->getJson($this->url('/status'))->assertJsonPath('data.failed', 0)->assertJsonPath('data.generated', 2);
        $this->assertEquals($okStamp, IssuedCertificate::where('attendee_id', $ok->id)->value('generated_at'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'certificate.retried', 'auditable_id' => $this->certificate->id]);
    }

    public function test_regenerate_rebuilds_one_attendees_file(): void
    {
        $people = $this->publishWith(2);
        $row = IssuedCertificate::where('attendee_id', $people[0]->id)->firstOrFail();
        Storage::disk('local')->delete($row->file_path);
        $this->travel(5)->minutes();

        $this->postJson($this->url("/attendees/{$people[0]->id}/regenerate"))->assertOk();

        $this->assertTrue(Storage::disk('local')->exists($row->file_path));
        $this->assertTrue($row->fresh()->generated_at->gt($row->generated_at));
        $this->assertDatabaseHas('audit_logs', ['action' => 'certificate.regenerated', 'auditable_id' => $this->certificate->id]);
        $this->postJson($this->url('/attendees/999999/regenerate'))->assertNotFound();
    }

    public function test_zip_contains_the_existing_files_only(): void
    {
        $people = $this->publishWith(3);
        IssuedCertificate::where('attendee_id', $people[2]->id)->update(['status' => 'failed', 'file_path' => null]);

        $response = $this->get($this->url('/download-all'))->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($tmp, $response->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp));
        $this->assertSame(2, $zip->numFiles);
        $this->assertStringStartsWith('%PDF-', $zip->getFromIndex(0));
        $zip->close();
        unlink($tmp);
    }

    public function test_zip_with_no_files_is_not_found(): void
    {
        $this->get($this->url('/download-all'))->assertNotFound();
    }

    public function test_all_actions_are_admin_only_and_organization_scoped(): void
    {
        $people = $this->publishWith(1);
        $calls = fn () => [
            $this->getJson($this->url('/status')),
            $this->postJson($this->url('/retry-failed')),
            $this->postJson($this->url("/attendees/{$people[0]->id}/regenerate")),
            $this->get($this->url('/download-all')),
        ];

        $this->actingAs(User::factory()->checker()->for($this->org)->create(), 'sanctum');
        foreach ($calls() as $response) {
            $response->assertForbidden();
        }

        $this->actingAs(User::factory()->orgAdmin()->for(Organization::factory()->create())->create(), 'sanctum');
        foreach ($calls() as $response) {
            $response->assertNotFound();
        }
    }

    public function test_writes_are_blocked_on_archived_events_but_reads_work(): void
    {
        $people = $this->publishWith(1);
        IssuedCertificate::where('attendee_id', $people[0]->id)->update(['status' => 'failed', 'failure_reason' => 'x']);
        $this->event->forceFill(['archived_at' => now()])->save();

        $this->postJson($this->url('/retry-failed'))->assertForbidden();
        $this->postJson($this->url("/attendees/{$people[0]->id}/regenerate"))->assertForbidden();
        $this->getJson($this->url('/status'))->assertOk()->assertJsonPath('data.failed', 1);
        $this->assertSame('failed', IssuedCertificate::first()->status->value);
    }

    public function test_retry_and_regenerate_need_a_published_certificate(): void
    {
        $people = $this->publishWith(1);
        $this->postJson($this->url('/unpublish'))->assertOk();

        $this->postJson($this->url('/retry-failed'))->assertUnprocessable();
        $this->postJson($this->url("/attendees/{$people[0]->id}/regenerate"))->assertUnprocessable();
    }
}
