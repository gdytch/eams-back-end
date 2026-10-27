<?php

namespace Tests\Feature;

use App\Jobs\GenerateAttendeeIdCardJob;
use App\Models\Attendee;
use App\Models\Certificate;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CertificateAudienceSyncTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $org;

    private Event $event;

    private Certificate $certificate;

    private Union $union;

    private Union $otherUnion;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->org = Organization::factory()->create();
        $this->event = Event::factory()->for($this->org)->create();
        $this->union = Union::factory()->create(['organization_id' => $this->org->id]);
        $this->otherUnion = Union::factory()->create(['organization_id' => $this->org->id]);
        $this->certificate = Certificate::factory()->for($this->event)->create(['audience_union_ids' => [$this->union->id]]);
        $this->actingAs(User::factory()->orgAdmin()->for($this->org)->create(), 'sanctum');
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/events/{$this->event->id}/certificates/{$this->certificate->id}".$suffix;
    }

    private function publish(): void
    {
        $this->postJson($this->url('/template'), ['template' => UploadedFile::fake()->image('t.png', 600, 400)])->assertOk();
        $this->postJson($this->url('/publish'))->assertOk();
        // Generation (a Bus batch) has run; from here the ID card job, which needs ImageMagick, is faked.
        Bus::fake([GenerateAttendeeIdCardJob::class]);
    }

    private function attendeeIn(Union $union): Attendee
    {
        return Attendee::factory()->for($this->event)->create(['union_id' => $union->id]);
    }

    private function generated(): int
    {
        return $this->getJson($this->url())->json('data.generation.generated');
    }

    private function total(): int
    {
        return $this->getJson($this->url())->json('data.generation.total');
    }

    private function download(Attendee $attendee): TestResponse
    {
        return $this->get($this->url("/attendees/{$attendee->id}/download"));
    }

    private function path(Attendee $attendee): string
    {
        return "events/{$this->event->id}/certificates/{$this->certificate->id}/{$attendee->id}.pdf";
    }

    public function test_a_newly_fully_registered_attendee_in_the_audience_gets_a_file(): void
    {
        $this->publish();
        $attendee = Attendee::factory()->for($this->event)->draft()->create(['union_id' => $this->union->id]);
        $this->assertSame(0, $this->total());

        $this->postJson("/api/v1/attendees/{$attendee->id}/finish")->assertOk();

        $this->assertSame(1, $this->total());
        $this->assertStringStartsWith('%PDF-', $this->download($attendee)->assertOk()->streamedContent());
    }

    public function test_a_newly_registered_attendee_outside_the_audience_gets_nothing(): void
    {
        $this->publish();
        $attendee = Attendee::factory()->for($this->event)->draft()->create(['union_id' => $this->otherUnion->id]);

        $this->postJson("/api/v1/attendees/{$attendee->id}/finish")->assertOk();

        $this->assertSame(0, $this->total());
        $this->download($attendee)->assertNotFound();
    }

    public function test_moving_into_the_audience_adds_a_file(): void
    {
        $this->publish();
        $attendee = $this->attendeeIn($this->otherUnion);

        $this->putJson("/api/v1/attendees/{$attendee->id}", ['union_id' => $this->union->id])->assertOk();

        $this->assertSame(1, $this->generated());
        $this->download($attendee)->assertOk();
    }

    public function test_leaving_the_audience_hides_the_file_and_returning_restores_it(): void
    {
        $attendee = $this->attendeeIn($this->union);
        $this->publish();
        $this->assertSame(1, $this->generated());

        $this->putJson("/api/v1/attendees/{$attendee->id}", ['union_id' => $this->otherUnion->id])->assertOk();

        $this->assertSame(0, $this->total());
        $this->assertSame(0, $this->generated());
        $this->download($attendee)->assertNotFound();
        Storage::disk('local')->assertExists($this->path($attendee));

        $this->putJson("/api/v1/attendees/{$attendee->id}", ['union_id' => $this->union->id])->assertOk();

        $this->assertSame(1, $this->total());
        $this->assertSame(1, $this->generated());
        $this->download($attendee)->assertOk();
    }

    public function test_a_name_change_regenerates_that_one_file(): void
    {
        $changed = $this->attendeeIn($this->union);
        $untouched = $this->attendeeIn($this->union);
        $this->publish();
        $disk = Storage::disk('local');
        $disk->put($this->path($changed), 'stale');
        $disk->put($this->path($untouched), 'stale');

        $this->putJson("/api/v1/attendees/{$changed->id}", ['first_name' => 'Renamed'])->assertOk();

        $this->assertStringStartsWith('%PDF-', $disk->get($this->path($changed)));
        $this->assertSame('stale', $disk->get($this->path($untouched)));
    }

    public function test_an_unrelated_change_does_not_regenerate(): void
    {
        $attendee = $this->attendeeIn($this->union);
        $this->publish();
        Storage::disk('local')->put($this->path($attendee), 'stale');

        $this->putJson("/api/v1/attendees/{$attendee->id}", ['remarks' => 'hello'])->assertOk();

        $this->assertSame('stale', Storage::disk('local')->get($this->path($attendee)));
    }

    public function test_nothing_runs_for_a_draft_certificate(): void
    {
        Bus::fake([GenerateAttendeeIdCardJob::class]);
        $attendee = $this->attendeeIn($this->otherUnion);

        $this->putJson("/api/v1/attendees/{$attendee->id}", ['union_id' => $this->union->id])->assertOk();

        $this->assertSame(0, $this->total());
        $this->assertSame([], Storage::disk('local')->allFiles("events/{$this->event->id}/certificates"));
    }

    public function test_nothing_runs_for_an_unpublished_certificate(): void
    {
        $this->publish();
        $this->postJson($this->url('/unpublish'))->assertOk();
        $attendee = $this->attendeeIn($this->otherUnion);

        $this->putJson("/api/v1/attendees/{$attendee->id}", ['union_id' => $this->union->id])->assertOk();

        $this->assertSame(0, $this->total());
    }

    public function test_nothing_runs_for_an_archived_event(): void
    {
        $attendee = $this->attendeeIn($this->otherUnion);
        $this->publish();
        $this->event->forceFill(['archived_at' => now()])->save();

        // Archived-event writes are blocked at the API, so save directly to prove the sync itself stands down.
        $attendee->update(['union_id' => $this->union->id, 'first_name' => 'Late']);

        $this->assertSame(0, $this->certificate->issuedCertificates()->count());
    }
}
