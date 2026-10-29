<?php

namespace Tests\Feature;

use App\Exports\EventOverviewAttendanceSheetExport;
use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\Breakout;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Check-ins at breakouts with attendance off are ignored wherever attendance is counted or listed. */
class HiddenBreakoutCheckInsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $org;

    private User $admin;

    private Event $event;

    private Attendee $registration;

    private Breakout $breakout;

    private AttendanceRecord $record;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->orgAdmin()->for($this->org)->create();
        $this->event = Event::factory()->for($this->org)->create(['status' => 'completed']);
        $this->registration = $this->registerInEvent($this->event, Attendee::factory()->for($this->org)->create(['first_name' => 'Hidden', 'last_name' => 'Scanner']));
        $this->breakout = Breakout::factory()->for($this->event)->create(['attendance_enabled' => false]);
        $this->record = AttendanceRecord::factory()->for($this->registration)->create([
            'event_session_id' => null,
            'breakout_id' => $this->breakout->id,
            'check_in_at' => now(),
            'check_out_at' => now(),
            'recorded_by' => $this->admin->id,
        ]);
    }

    private function setAttendance(bool $on): void
    {
        $this->breakout->update(['attendance_enabled' => $on]);
    }

    public function test_dashboard_registration_insights_ignore_them_and_restore_when_on(): void
    {
        $url = '/api/v1/dashboard';
        $this->actingAs($this->admin, 'sanctum')->getJson($url)->assertOk()
            ->assertJsonPath('registration_summary.checked_in', 0);

        $this->setAttendance(true);
        $this->actingAs($this->admin, 'sanctum')->getJson($url)->assertOk()
            ->assertJsonPath('registration_summary.checked_in', 1);
    }

    public function test_report_latest_event_stats_and_trend_ignore_them(): void
    {
        $url = "/api/v1/organizations/{$this->org->id}/reports/dashboard";
        $response = $this->actingAs($this->admin, 'sanctum')->getJson($url)->assertOk();
        $response->assertJsonPath('latest_event.stats.checked_in', 0)
            ->assertJsonPath('latest_event.stats.checked_out', 0)
            ->assertJsonPath('attendance_rate_trend.0.checked_in', 0);

        $this->setAttendance(true);
        $response = $this->actingAs($this->admin, 'sanctum')->getJson($url)->assertOk();
        $response->assertJsonPath('latest_event.stats.checked_in', 1)
            ->assertJsonPath('attendance_rate_trend.0.checked_in', 1);
    }

    public function test_cascaded_session_records_still_count(): void
    {
        $session = EventSession::factory()->for($this->event)->create();
        AttendanceRecord::factory()->for($this->registration)->create([
            'event_session_id' => $session->id,
            'breakout_id' => null,
            'via_breakout_id' => $this->breakout->id,
            'check_in_at' => now(),
            'recorded_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/organizations/{$this->org->id}/reports/dashboard")->assertOk()
            ->assertJsonPath('latest_event.stats.checked_in', 1);
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('registration_summary.checked_in', 1);
    }

    public function test_event_overview_attendance_sheet_ignores_them(): void
    {
        $rows = fn () => $this->sheetRows();

        $this->assertSame([], $rows());

        $this->setAttendance(true);
        $this->assertSame(['Hidden'], $rows());
    }

    /** @return list<string> first names listed on the All Sessions sheet */
    private function sheetRows(): array
    {
        $xlsx = Excel::raw(new EventOverviewAttendanceSheetExport($this->event->fresh()), \Maatwebsite\Excel\Excel::XLSX);
        $path = tempnam(sys_get_temp_dir(), 'ams-export-');
        file_put_contents($path, $xlsx);

        try {
            $rows = IOFactory::load($path)->getActiveSheet()->toArray();
        } finally {
            unlink($path);
        }

        return array_values(array_map(fn ($row) => $row[0], array_slice($rows, 1)));
    }

    public function test_dashboard_recent_scans_ignore_them(): void
    {
        $checker = User::factory()->checker()->for($this->org)->create();
        $this->record->update(['recorded_by' => $checker->id]);

        $this->actingAs($checker, 'sanctum')->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonCount(0, 'recent_scans');

        $this->setAttendance(true);
        $this->actingAs($checker, 'sanctum')->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonCount(1, 'recent_scans');
    }

    public function test_global_search_attendance_ignores_them(): void
    {
        $url = '/api/v1/search?q=Hidden&category=attendance';
        $ids = fn () => collect($this->actingAs($this->admin, 'sanctum')->getJson($url)->assertOk()->json('groups'))
            ->firstWhere('key', 'attendance')['items'] ?? [];

        $this->assertCount(0, $ids());

        $this->setAttendance(true);
        $this->assertSame([$this->record->id], array_column($ids(), 'id'));
    }

    public function test_event_attendance_summary_records_table_ignores_them(): void
    {
        $url = "/api/v1/events/{$this->event->id}/reports/attendance-summary";
        $this->actingAs($this->admin, 'sanctum')->getJson($url)->assertOk()
            ->assertJsonCount(0, 'data.attendance_records.data');

        $this->setAttendance(true);
        $this->actingAs($this->admin, 'sanctum')->getJson($url)->assertOk()
            ->assertJsonCount(1, 'data.attendance_records.data');
    }
}
