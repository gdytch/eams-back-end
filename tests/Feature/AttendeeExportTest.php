<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Mission;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AttendeeExportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_export_attendees_as_xlsx(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        Attendee::factory(3)->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')
            ->getJson('/api/v1/attendees/export?format=xlsx');

        $response->assertOk();
        $this->assertStringContainsString('spreadsheet', $response->headers->get('Content-Type'));
    }

    public function test_export_attendees_as_pdf(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        Attendee::factory(3)->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')
            ->getJson('/api/v1/attendees/export?format=pdf');

        $response->assertOk();
        $this->assertStringContainsString('pdf', $response->headers->get('Content-Type'));
    }

    public function test_export_defaults_to_xlsx(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        Attendee::factory(3)->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')
            ->getJson('/api/v1/attendees/export');

        $response->assertOk();
        $this->assertStringContainsString('spreadsheet', $response->headers->get('Content-Type'));
    }

    public function test_export_attendees_filtered_by_event(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        $event = Event::factory()->for($org)->create();
        $attendee1 = Attendee::factory()->for($org)->create();
        $attendee2 = Attendee::factory()->for($org)->create();
        $attendee3 = Attendee::factory()->for($org)->create();

        EventRegistration::factory()->for($event)->for($attendee1)->create();
        EventRegistration::factory()->for($event)->for($attendee2)->create();

        $response = $this->actingAs($checker, 'sanctum')
            ->getJson("/api/v1/attendees/export?format=xlsx&event_id={$event->id}");

        $response->assertOk();
        $this->assertStringContainsString('spreadsheet', $response->headers->get('Content-Type'));
    }

    public function test_export_attendees_with_search_filter(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        Attendee::factory()->for($org)->create(['first_name' => 'John', 'last_name' => 'Doe']);
        Attendee::factory()->for($org)->create(['first_name' => 'Jane', 'last_name' => 'Smith']);

        $response = $this->actingAs($checker, 'sanctum')
            ->getJson('/api/v1/attendees/export?format=xlsx&search=John');

        $response->assertOk();
        $this->assertStringContainsString('spreadsheet', $response->headers->get('Content-Type'));
    }

    public function test_export_attendees_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/attendees/export');

        $response->assertUnauthorized();
    }

    public function test_export_attendees_requires_view_any_permission(): void
    {
        $org = Organization::factory()->create();
        $attendee = User::factory()->attendee()->for($org)->create();

        $response = $this->actingAs($attendee, 'sanctum')
            ->getJson('/api/v1/attendees/export');

        $response->assertForbidden();
    }

    public function test_super_admin_can_export_attendees_from_any_org(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        $superAdmin = User::factory()->superAdmin()->for($org1)->create();

        Attendee::factory(2)->for($org1)->create();
        Attendee::factory(2)->for($org2)->create();

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/v1/attendees/export?format=xlsx');

        $response->assertOk();
    }

    public function test_export_attendees_pdf_shows_event_name(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        $event = Event::factory()->for($org)->create(['name' => 'Leadership Summit 2024']);
        $attendee = Attendee::factory()->for($org)->create();
        EventRegistration::factory()->for($event)->for($attendee)->create();

        $response = $this->actingAs($checker, 'sanctum')
            ->getJson("/api/v1/attendees/export?format=pdf&event_id={$event->id}");

        $response->assertOk();
        $this->assertStringContainsString('pdf', $response->headers->get('Content-Type'));
    }

    public function test_export_attendees_includes_relationships(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $union = Union::factory()->for($org)->create();
        $mission = Mission::factory()->for($org)->for($union)->create();
        $church = Church::factory()->for($org)->create();

        Attendee::factory()
            ->for($org)
            ->for($union)
            ->for($mission)
            ->for($church)
            ->create([
                'first_name' => 'Test',
                'last_name' => 'Attendee',
                'mobile_no' => '+1234567890',
                'email_address' => 'test@example.com',
            ]);

        $response = $this->actingAs($checker, 'sanctum')
            ->getJson('/api/v1/attendees/export?format=xlsx');

        $response->assertOk();
        $this->assertStringContainsString('spreadsheet', $response->headers->get('Content-Type'));
    }
}
