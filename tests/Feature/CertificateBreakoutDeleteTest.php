<?php

namespace Tests\Feature;

use App\Jobs\GenerateAttendeeIdCardJob;
use App\Models\Breakout;
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

class CertificateBreakoutDeleteTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $org = Organization::factory()->create();
        $this->event = Event::factory()->for($org)->create();
        $this->actingAs(User::factory()->orgAdmin()->for($org)->create(), 'sanctum');
    }

    private function certificate(array $audience): Certificate
    {
        return Certificate::factory()->for($this->event)->create($audience);
    }

    private function publish(Certificate $certificate): void
    {
        $url = "/api/v1/events/{$this->event->id}/certificates/{$certificate->id}";
        $this->postJson("$url/template", ['template' => UploadedFile::fake()->image('t.png', 600, 400)])->assertOk();
        $this->postJson("$url/publish")->assertOk();
        Bus::fake([GenerateAttendeeIdCardJob::class]);
    }

    private function deleteBreakout(Breakout $breakout)
    {
        return $this->deleteJson("/api/v1/events/{$this->event->id}/breakouts/{$breakout->id}");
    }

    public function test_deleting_a_used_breakout_removes_its_id_from_audiences(): void
    {
        [$gone, $kept] = Breakout::factory()->for($this->event)->count(2)->create();
        $union = Union::factory()->create(['organization_id' => $this->event->organization_id]);
        $certificate = $this->certificate(['audience_breakout_ids' => [$gone->id, $kept->id], 'audience_union_ids' => [$union->id]]);

        $this->deleteBreakout($gone)->assertNoContent();

        $this->assertSame([$kept->id], $certificate->fresh()->audience_breakout_ids);
    }

    public function test_delete_is_refused_when_it_would_empty_a_published_audience(): void
    {
        $breakout = Breakout::factory()->for($this->event)->create();
        Breakout::factory()->for($this->event)->create();
        $certificate = $this->certificate(['audience_breakout_ids' => [$breakout->id]]);
        $this->publish($certificate);

        $this->deleteBreakout($breakout)->assertUnprocessable()->assertJsonValidationErrors('breakout');

        $this->assertModelExists($breakout);
        $this->assertSame([$breakout->id], $certificate->fresh()->audience_breakout_ids);
    }

    public function test_a_draft_audience_may_become_empty(): void
    {
        $breakout = Breakout::factory()->for($this->event)->create();
        Breakout::factory()->for($this->event)->create();
        $certificate = $this->certificate(['audience_breakout_ids' => [$breakout->id]]);

        $this->deleteBreakout($breakout)->assertNoContent();

        $this->assertSame([], $certificate->fresh()->audience_breakout_ids);
    }

    public function test_published_audience_with_other_selections_is_resynced(): void
    {
        $breakout = Breakout::factory()->for($this->event)->create();
        Breakout::factory()->for($this->event)->create();
        $union = Union::factory()->create(['organization_id' => $this->event->organization_id]);
        $certificate = $this->certificate(['audience_breakout_ids' => [$breakout->id], 'audience_union_ids' => [$union->id]]);
        $this->publish($certificate);

        $this->deleteBreakout($breakout)->assertNoContent();

        $this->assertSame([], $certificate->fresh()->audience_breakout_ids);
    }
}
