<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class EventRegistrationQrExportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_org_admin_can_export_qr_codes_as_pdf(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        Attendee::factory()->for($org)->count(3)->create()->each(
            fn (Attendee $attendee) => EventRegistration::factory()->for($event)->for($attendee)->create()
        );

        $response = $this->actingAs($orgAdmin, 'sanctum')->get("/api/v1/events/{$event->id}/registrations/qr-export");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'event_registration.qr_pdf_exported',
            'auditable_type' => 'App\Models\Event',
            'auditable_id' => $event->id,
        ]);
    }

    public function test_checker_cannot_export_qr_codes(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')->get("/api/v1/events/{$event->id}/registrations/qr-export");

        $response->assertForbidden();
    }

    public function test_qr_export_can_be_limited_to_selected_registrations(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $registrations = Attendee::factory()->for($org)->count(3)->create()->map(
            fn (Attendee $attendee) => EventRegistration::factory()->for($event)->for($attendee)->create()
        );

        $response = $this->actingAs($orgAdmin, 'sanctum')->get(
            "/api/v1/events/{$event->id}/registrations/qr-export?registration_ids[]={$registrations->first()->id}"
        );

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }
}
