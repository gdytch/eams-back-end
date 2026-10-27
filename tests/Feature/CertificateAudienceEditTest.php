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
use Tests\TestCase;

class CertificateAudienceEditTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Event $event;

    private Certificate $certificate;

    private Union $union;

    private Union $otherUnion;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $org = Organization::factory()->create();
        $this->event = Event::factory()->for($org)->create();
        $this->union = Union::factory()->create(['organization_id' => $org->id]);
        $this->otherUnion = Union::factory()->create(['organization_id' => $org->id]);
        $this->certificate = Certificate::factory()->for($this->event)->create(['audience_union_ids' => [$this->union->id]]);
        $this->actingAs(User::factory()->orgAdmin()->for($org)->create(), 'sanctum');
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/events/{$this->event->id}/certificates/{$this->certificate->id}".$suffix;
    }

    private function publish(): void
    {
        $this->postJson($this->url('/template'), ['template' => UploadedFile::fake()->image('t.png', 600, 400)])->assertOk();
        $this->postJson($this->url('/publish'))->assertOk();
        Bus::fake([GenerateAttendeeIdCardJob::class]);
    }

    private function attendeeIn(Union $union): Attendee
    {
        return Attendee::factory()->for($this->event)->create(['union_id' => $union->id]);
    }

    private function counts(): array
    {
        return $this->getJson($this->url())->json('data.generation');
    }

    private function setAudience(array $unionIds): void
    {
        $this->putJson($this->url(), ['audience' => ['union_ids' => $unionIds]])->assertOk();
    }

    public function test_adding_a_union_issues_files_for_the_newly_covered_attendees(): void
    {
        $existing = $this->attendeeIn($this->union);
        $added = $this->attendeeIn($this->otherUnion);
        $this->publish();
        $this->assertSame(1, $this->counts()['total']);

        $this->setAudience([$this->union->id, $this->otherUnion->id]);

        $this->assertSame(2, $this->counts()['total']);
        $this->assertSame(2, $this->counts()['generated']);
        $this->get($this->url("/attendees/{$added->id}/download"))->assertOk();
        $this->get($this->url("/attendees/{$existing->id}/download"))->assertOk();
    }

    public function test_removing_a_union_hides_its_rows_and_adding_it_back_restores_them(): void
    {
        $this->attendeeIn($this->union);
        $leaver = $this->attendeeIn($this->otherUnion);
        $this->certificate->update(['audience_union_ids' => [$this->union->id, $this->otherUnion->id]]);
        $this->publish();
        $this->assertSame(2, $this->counts()['total']);

        $this->setAudience([$this->union->id]);

        $this->assertSame(1, $this->counts()['total']);
        $this->get($this->url("/attendees/{$leaver->id}/download"))->assertNotFound();

        $this->setAudience([$this->union->id, $this->otherUnion->id]);

        $this->assertSame(2, $this->counts()['total']);
        $this->get($this->url("/attendees/{$leaver->id}/download"))->assertOk();
    }

    public function test_republish_hides_rows_of_attendees_no_longer_in_the_audience(): void
    {
        $this->attendeeIn($this->union);
        $leaver = $this->attendeeIn($this->otherUnion);
        $this->certificate->update(['audience_union_ids' => [$this->union->id, $this->otherUnion->id]]);
        $this->publish();
        $this->postJson($this->url('/unpublish'))->assertOk();
        $this->putJson($this->url(), ['audience' => ['union_ids' => [$this->union->id]]])->assertOk();

        $this->postJson($this->url('/publish'))->assertOk();

        $this->assertSame(1, $this->counts()['total']);
        $this->get($this->url("/attendees/{$leaver->id}/download"))->assertNotFound();
    }

    public function test_editing_the_audience_of_a_draft_creates_no_rows(): void
    {
        $this->attendeeIn($this->otherUnion);

        $this->setAudience([$this->otherUnion->id]);

        $this->assertSame(0, $this->certificate->issuedCertificates()->count());
    }
}
