<?php

namespace Tests\Feature;

use App\Jobs\GenerateAttendeeIdCardJob;
use App\Models\Attendee;
use App\Models\Breakout;
use App\Models\Certificate;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** A published Certificate whose Audience is a Breakout follows its pickers through every pick-save path. */
class CertificateBreakoutPickSyncTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $org;

    private Event $event;

    private Certificate $certificate;

    private Breakout $breakout;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->org = Organization::factory()->create();
        $this->event = Event::factory()->for($this->org)->create(['status' => 'published', 'pick_mode' => 'multiple', 'pick_min' => 0, 'pick_max' => null]);
        $this->breakout = Breakout::factory()->for($this->event)->create(['date' => '2026-06-25', 'start_time' => '09:00', 'end_time' => '10:00']);
        $this->certificate = Certificate::factory()->for($this->event)->create(['audience_breakout_ids' => [$this->breakout->id]]);
        $this->admin = User::factory()->orgAdmin()->for($this->org)->create();
        $this->actingAs($this->admin, 'sanctum');
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

    private function total(): int
    {
        return $this->getJson($this->url())->json('data.generation.total');
    }

    private function generated(): int
    {
        return $this->getJson($this->url())->json('data.generation.generated');
    }

    private function adminPick(Attendee $attendee, array $ids): void
    {
        $this->putJson("/api/v1/attendees/{$attendee->id}/breakouts", ['breakout_ids' => $ids])->assertOk();
    }

    private function pdfExists(Attendee $attendee): bool
    {
        return Storage::disk('local')->exists("events/{$this->event->id}/certificates/{$this->certificate->id}/{$attendee->id}.pdf");
    }

    public function test_admin_pick_creates_a_file_drop_hides_it_keeping_the_file_and_repick_restores_it(): void
    {
        $this->publish();
        $attendee = Attendee::factory()->for($this->event)->create();
        $this->assertSame(0, $this->total());

        $this->adminPick($attendee, [$this->breakout->id]);
        $this->assertSame(1, $this->generated());
        $this->get($this->url("/attendees/{$attendee->id}/download"))->assertOk();

        $this->adminPick($attendee, []);
        $this->assertSame(0, $this->total());
        $this->get($this->url("/attendees/{$attendee->id}/download"))->assertNotFound();
        $this->assertTrue($this->pdfExists($attendee));
        $this->assertSame(1, $this->certificate->issuedCertificates()->count());

        $this->adminPick($attendee, [$this->breakout->id]);
        $this->assertSame(1, $this->total());
        $this->assertSame(1, $this->generated());
        $this->get($this->url("/attendees/{$attendee->id}/download"))->assertOk();
    }

    public function test_picking_a_breakout_outside_the_audience_adds_nothing(): void
    {
        $this->publish();
        $other = Breakout::factory()->for($this->event)->create(['date' => '2026-06-25', 'start_time' => '11:00', 'end_time' => '12:00']);
        $attendee = Attendee::factory()->for($this->event)->create();

        $this->adminPick($attendee, [$other->id]);

        $this->assertSame(0, $this->total());
    }

    public function test_draft_certificates_create_no_rows(): void
    {
        $attendee = Attendee::factory()->for($this->event)->create();

        $this->adminPick($attendee, [$this->breakout->id]);

        $this->assertSame(0, $this->certificate->issuedCertificates()->count());
    }

    public function test_public_pick_save_of_a_draft_attendee_creates_no_row_until_fully_registered(): void
    {
        $this->publish();
        $user = User::factory()->attendee()->create(['organization_id' => $this->org->id]);
        $attendee = Attendee::factory()->draft()->for($this->event)->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/public/event/{$this->event->invite_token}/registration/breakouts", ['breakout_ids' => [$this->breakout->id]])
            ->assertOk();
        $this->assertSame(0, $this->certificate->issuedCertificates()->count());

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/attendees/{$attendee->id}/finish")->assertOk();
        $this->assertSame(1, $this->total());
    }

    public function test_event_level_pick_save_keeps_the_issued_certificates_of_existing_pickers(): void
    {
        $attendee = Attendee::factory()->for($this->event)->create();
        $attendee->breakouts()->attach($this->breakout->id);
        $this->publish();
        $this->assertSame(1, $this->generated());

        $this->putJson("/api/v1/events/{$this->event->id}/breakout-picks", ['mode' => 'multiple', 'min' => 0, 'max' => 3])->assertOk();

        $this->assertSame(1, $this->total());
        $this->get($this->url("/attendees/{$attendee->id}/download"))->assertOk();
    }
}
