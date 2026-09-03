<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class EventAttendanceReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function createEventWithAttendees(Organization $org, int $attendeeCount = 5): Event
    {
        $event = Event::factory()->for($org)->create();
        $session = EventSession::factory()->for($event)->create([
            'session_date' => now()->toDateString(),
            'start_time' => now()->format('H:i:s'),
            'end_time' => now()->addHours(4)->format('H:i:s'),
        ]);

        for ($i = 0; $i < $attendeeCount; $i++) {
            $attendee = Attendee::factory()->for($org)->create();
            $registration = EventRegistration::factory()
                ->for($event)
                ->for($attendee)
                ->create();

            // Mark some as checked in.
            if ($i < 3) {
                $registration->attendanceRecords()->create([
                    'event_session_id' => $session->id,
                    'check_in_at' => now(),
                    'method' => 'qr',
                    'recorded_by' => 1,
                ]);

                // Mark one as checked out.
                if ($i === 0) {
                    $registration->attendanceRecords->first()->update(['check_out_at' => now()->addMinute()]);
                }
            }
        }

        return $event;
    }

    public function test_event_attendance_summary_returns_correct_counts(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = $this->createEventWithAttendees($org, 5);

        $response = $this->actingAs($checker, 'sanctum')
            ->getJson("/api/v1/events/{$event->id}/reports/attendance-summary");

        $response->assertOk();
        $response->assertJsonPath('data.event_id', $event->id);
        $response->assertJsonPath('data.total_registered', 5);
        $response->assertJsonPath('data.total_checked_in', 3);
        $response->assertJsonPath('data.total_checked_out', 1);
    }

    public function test_event_attendance_summary_requires_view_permission(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org2)->create();
        $event = Event::factory()->for($org1)->create();

        $response = $this->actingAs($checker, 'sanctum')
            ->getJson("/api/v1/events/{$event->id}/reports/attendance-summary");

        $response->assertNotFound();
    }

    public function test_export_event_attendance_as_xlsx(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = $this->createEventWithAttendees($org, 3);

        $response = $this->actingAs($checker, 'sanctum')
            ->getJson("/api/v1/events/{$event->id}/reports/attendance-summary/export?format=xlsx");

        $response->assertOk();
        $this->assertStringContainsString('spreadsheet', $response->headers->get('Content-Type'));
    }

    public function test_export_event_attendance_as_pdf(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = $this->createEventWithAttendees($org, 3);

        $response = $this->actingAs($checker, 'sanctum')
            ->getJson("/api/v1/events/{$event->id}/reports/attendance-summary/export?format=pdf");

        $response->assertOk();
        $this->assertStringContainsString('pdf', $response->headers->get('Content-Type'));
    }

    public function test_organization_attendance_overview_requires_org_admin(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')
            ->getJson("/api/v1/organizations/{$org->id}/reports/attendance-overview");

        $response->assertForbidden();
    }

    public function test_organization_attendance_overview_super_admin_access(): void
    {
        $org = Organization::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $event = $this->createEventWithAttendees($org, 5);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/v1/organizations/{$org->id}/reports/attendance-overview");

        $response->assertOk();
        $response->assertJsonPath('organization_id', $org->id);
        $response->assertJsonPath('total_registered', 5);
    }

    public function test_organization_attendance_overview_org_admin_access(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        $event = $this->createEventWithAttendees($org, 5);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/organizations/{$org->id}/reports/attendance-overview");

        $response->assertOk();
        $response->assertJsonPath('organization_id', $org->id);
        $response->assertJsonPath('total_registered', 5);
    }

    public function test_organization_isolation_in_reports(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        $admin1 = User::factory()->orgAdmin()->for($org1)->create();

        $this->createEventWithAttendees($org1, 3);
        $this->createEventWithAttendees($org2, 5);

        $response = $this->actingAs($admin1, 'sanctum')
            ->getJson("/api/v1/organizations/{$org1->id}/reports/attendance-overview");

        $response->assertOk();
        // Should only see org1's registrations, not org2's.
        $response->assertJsonPath('total_registered', 3);
    }
}
