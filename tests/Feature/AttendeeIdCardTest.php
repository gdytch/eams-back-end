<?php

namespace Tests\Feature;

use App\Jobs\GenerateAttendeeIdCardJob;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttendeeIdCardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_registering_an_attendee_generates_an_id_card_pdf(): void
    {
        Storage::fake('local');

        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson("/api/v1/events/{$event->id}/registrations", [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
        ]);

        $response->assertCreated();

        $registration = EventRegistration::firstOrFail();
        $expectedPath = "{$event->id}/{$registration->attendee_id}.pdf";

        Storage::disk('local')->assertExists($expectedPath);
        $this->assertNotNull($registration->fresh()->id_card_generated_at);
    }

    public function test_id_card_download_returns_202_while_generation_is_pending(): void
    {
        Queue::fake();
        Storage::fake('local');

        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $registration = EventRegistration::factory()
            ->for($event)
            ->for(Attendee::factory()->for($org))
            ->create();

        $response = $this->actingAs($checker, 'sanctum')
            ->get("/api/v1/events/{$event->id}/registrations/{$registration->id}/id-card");

        $response->assertStatus(202);
    }

    public function test_id_card_download_returns_the_pdf_once_generated(): void
    {
        Storage::fake('local');

        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $registration = EventRegistration::factory()
            ->for($event)
            ->for(Attendee::factory()->for($org))
            ->create();

        (new GenerateAttendeeIdCardJob($registration))->handle();

        $response = $this->actingAs($checker, 'sanctum')
            ->get("/api/v1/events/{$event->id}/registrations/{$registration->id}/id-card");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_regenerate_id_card_requires_org_admin(): void
    {
        Queue::fake();

        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $registration = EventRegistration::factory()
            ->for($event)
            ->for(Attendee::factory()->for($org))
            ->create();

        $response = $this->actingAs($checker, 'sanctum')
            ->post("/api/v1/events/{$event->id}/registrations/{$registration->id}/id-card/regenerate");

        $response->assertForbidden();
        Queue::assertNotPushed(GenerateAttendeeIdCardJob::class);
    }

    public function test_org_admin_can_regenerate_id_card(): void
    {
        Queue::fake();

        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $registration = EventRegistration::factory()
            ->for($event)
            ->for(Attendee::factory()->for($org))
            ->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->post("/api/v1/events/{$event->id}/registrations/{$registration->id}/id-card/regenerate");

        $response->assertStatus(202);
        Queue::assertPushed(GenerateAttendeeIdCardJob::class);
    }

    public function test_org_admin_can_upload_an_id_card_background(): void
    {
        Storage::fake('public');

        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')->post(
            "/api/v1/events/{$event->id}/id-card-background",
            ['background' => UploadedFile::fake()->image('background.png')]
        );

        $response->assertOk();
        $this->assertNotNull($response->json('data.id_card_background_url'));
        Storage::disk('public')->assertExists($event->fresh()->id_card_background_path);
    }

    public function test_checker_cannot_upload_an_id_card_background(): void
    {
        Storage::fake('public');

        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')->post(
            "/api/v1/events/{$event->id}/id-card-background",
            ['background' => UploadedFile::fake()->image('background.png')]
        );

        $response->assertForbidden();
    }

    public function test_id_card_uses_the_events_background_when_present(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->create();
        $path = UploadedFile::fake()->image('background.png')->store("events/{$event->id}", 'public');
        $event->update(['id_card_background_path' => $path]);

        $registration = EventRegistration::factory()
            ->for($event)
            ->for(Attendee::factory()->for($org))
            ->create();

        (new GenerateAttendeeIdCardJob($registration))->handle();

        Storage::disk('local')->assertExists("{$event->id}/{$registration->attendee_id}.pdf");
    }
}
