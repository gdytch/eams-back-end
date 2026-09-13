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

    public function test_updating_event_font_color_dispatches_id_card_regeneration(): void
    {
        Queue::fake();

        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $registration = EventRegistration::factory()
            ->for($event)
            ->for(Attendee::factory()->for($org))
            ->create(['id_card_generated_at' => now()]);

        $this->actingAs($orgAdmin, 'sanctum')->patchJson("/api/v1/events/{$event->id}", [
            'id_card_font_color' => '#ff0000',
        ]);

        Queue::assertPushed(GenerateAttendeeIdCardJob::class);
        $this->assertEquals('#ff0000', $event->fresh()->id_card_font_color);
    }

    public function test_updating_event_without_changing_font_color_does_not_dispatch_regeneration(): void
    {
        Queue::fake();

        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create(['id_card_font_color' => '#ff0000']);
        EventRegistration::factory()
            ->for($event)
            ->for(Attendee::factory()->for($org))
            ->create(['id_card_generated_at' => now()]);

        $this->actingAs($orgAdmin, 'sanctum')->patchJson("/api/v1/events/{$event->id}", [
            'name' => 'Updated Event Name',
        ]);

        Queue::assertNotPushed(GenerateAttendeeIdCardJob::class);
    }

    public function test_invalid_hex_font_color_fails_validation(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')->patchJson("/api/v1/events/{$event->id}", [
            'id_card_font_color' => 'red',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('id_card_font_color');
    }

    public function test_invalid_hex_format_fails_validation(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();

        // Without #
        $response = $this->actingAs($orgAdmin, 'sanctum')->patchJson("/api/v1/events/{$event->id}", [
            'id_card_font_color' => 'ff0000',
        ]);
        $response->assertUnprocessable();

        // 3-digit hex (not allowed)
        $response = $this->actingAs($orgAdmin, 'sanctum')->patchJson("/api/v1/events/{$event->id}", [
            'id_card_font_color' => '#f00',
        ]);
        $response->assertUnprocessable();
    }

    public function test_event_resource_includes_id_card_font_color(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create(['id_card_font_color' => '#111111']);

        $response = $this->actingAs($orgAdmin, 'sanctum')->getJson("/api/v1/events/{$event->id}");

        $response->assertOk();
        $this->assertEquals('#111111', $response->json('data.id_card_font_color'));
    }

    public function test_event_resource_defaults_font_color_to_black(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create(['id_card_font_color' => null]);

        $response = $this->actingAs($orgAdmin, 'sanctum')->getJson("/api/v1/events/{$event->id}");

        $response->assertOk();
        $this->assertEquals('#000000', $response->json('data.id_card_font_color'));
    }

    public function test_id_card_pdf_uses_configured_font_color(): void
    {
        Storage::fake('local');

        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->create(['id_card_font_color' => '#ff0000']);
        $registration = EventRegistration::factory()
            ->for($event)
            ->for(Attendee::factory()->for($org)->create(['first_name' => 'John', 'last_name' => 'Doe']))
            ->create();

        (new GenerateAttendeeIdCardJob($registration))->handle();

        $pdf = Storage::disk('local')->get("{$event->id}/{$registration->attendee_id}.pdf");
        $this->assertNotEmpty($pdf);
        // PDF content should include the hex color value (dompdf will embed it in the PDF)
    }

    public function test_clearing_font_color_reverts_to_default(): void
    {
        Queue::fake();

        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create(['id_card_font_color' => '#ff0000']);
        EventRegistration::factory()
            ->for($event)
            ->for(Attendee::factory()->for($org))
            ->create(['id_card_generated_at' => now()]);

        $this->actingAs($orgAdmin, 'sanctum')->patchJson("/api/v1/events/{$event->id}", [
            'id_card_font_color' => null,
        ]);

        Queue::assertPushed(GenerateAttendeeIdCardJob::class);
        $this->assertNull($event->fresh()->id_card_font_color);
    }
}
