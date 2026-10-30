<?php

namespace Tests\Feature;

use App\Exports\AttendeeSummaryExport;
use App\Exports\EventAttendanceExport;
use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\Breakout;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class BreakoutReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $org;

    private Event $event;

    private Breakout $breakout;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-25 12:00:00');
        $this->org = Organization::factory()->create();
        $this->event = Event::factory()->for($this->org)->create(['status' => 'published']);
        $this->breakout = Breakout::factory()->for($this->event)->create([
            'title' => 'Youth Track', 'date' => '2026-06-25', 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 2,
        ]);
        $this->admin = User::factory()->orgAdmin()->for($this->org)->create();
    }

    private function attendee(bool $picked = true, array $attributes = []): Attendee
    {
        $attendee = Attendee::factory()->for($this->event)->for($this->org)->create($attributes + ['first_registered_at' => '2026-06-20 08:00:00']);
        if ($picked) {
            $attendee->breakouts()->attach($this->breakout->id);
        }

        return $attendee;
    }

    private function checkIn(Attendee $attendee, array $extra = [], ?Breakout $breakout = null): AttendanceRecord
    {
        return AttendanceRecord::create($extra + [
            'attendee_id' => $attendee->id,
            'breakout_id' => ($breakout ?? $this->breakout)->id,
            'check_in_at' => '2026-06-25 09:05:00',
            'method' => 'qr',
            'recorded_by' => $this->admin->id,
        ]);
    }

    /** Three pick holders (one present, two absent), one walk-in, one late registrant who never picked. */
    private function seedBreakoutData(): void
    {
        $this->checkIn($this->attendee());
        $this->attendee();
        $this->attendee();
        $this->checkIn($this->attendee(false), ['is_walk_in' => true, 'method' => 'manual']);
        $this->attendee(false);
    }

    public function test_report_counts_follow_the_shared_rule(): void
    {
        $this->seedBreakoutData();

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/events/{$this->event->id}/reports/breakouts")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.breakout_id', $this->breakout->id)
            ->assertJsonPath('data.0.capacity', 2)
            ->assertJsonPath('data.0.picks', 3)
            ->assertJsonPath('data.0.overbooked', true)
            ->assertJsonPath('data.0.expected', 3)
            ->assertJsonPath('data.0.present', 2)
            ->assertJsonPath('data.0.absent', 2)
            ->assertJsonPath('data.0.walk_ins', 1)
            ->assertJsonPath('data.0.rate', 33.3);
    }

    public function test_breakout_that_has_not_ended_reports_upcoming_not_absent(): void
    {
        $this->seedBreakoutData();
        Carbon::setTestNow('2026-06-25 09:30:00');

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/events/{$this->event->id}/reports/breakouts")
            ->assertJsonPath('data.0.absent', 0)
            ->assertJsonPath('data.0.upcoming', 2)
            ->assertJsonPath('data.0.rate', 0);
    }

    public function test_roster_lists_pick_holders_and_walk_ins_with_status_and_recorder(): void
    {
        $this->seedBreakoutData();

        $rows = collect($this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/events/{$this->event->id}/reports/breakouts/{$this->breakout->id}")
            ->assertOk()->assertJsonCount(4, 'data')->json('data'));

        $this->assertSame(['Absent' => 2, 'Present' => 1, 'Walk-in' => 1], $rows->countBy('status')->sortKeys()->all());
        $walkIn = $rows->firstWhere('status', 'Walk-in');
        $this->assertSame('manual', $walkIn['method']);
        $this->assertSame($this->admin->name, $walkIn['recorded_by']);
        $this->assertSame('2026-06-25 09:05:00', $walkIn['check_in_at']);
        $this->assertNull($rows->firstWhere('status', 'Absent')['check_in_at']);
    }

    public function test_roster_rows_carry_the_attendance_record_id_walk_ins_included(): void
    {
        $present = $this->checkIn($this->attendee());
        $this->attendee();
        $walkIn = $this->checkIn($this->attendee(false), ['is_walk_in' => true, 'method' => 'manual']);

        $rows = collect($this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/events/{$this->event->id}/reports/breakouts/{$this->breakout->id}")
            ->assertOk()->json('data'));

        $this->assertEqualsCanonicalizing([$present->id, $walkIn->id, null], $rows->pluck('attendance_record_id')->all());
        $this->assertSame($walkIn->id, $rows->firstWhere('status', 'Walk-in')['attendance_record_id']);
    }

    public function test_report_endpoints_follow_view_staff_data(): void
    {
        $otherOrg = Organization::factory()->create();
        $outsider = User::factory()->orgAdmin()->for($otherOrg)->create();
        $attendeeUser = User::factory()->attendee()->for($this->org)->create();
        $otherBreakout = Breakout::factory()->for(Event::factory()->for($this->org)->create())->create();
        $base = "/api/v1/events/{$this->event->id}/reports/breakouts";

        $this->getJson($base)->assertUnauthorized();
        $this->actingAs($outsider, 'sanctum')->getJson($base)->assertNotFound();
        $this->actingAs($attendeeUser, 'sanctum')->getJson($base)->assertNotFound();
        $this->actingAs(User::factory()->checker()->for($this->org)->create(), 'sanctum')->getJson($base)->assertOk();
        // A breakout of another event never resolves under this event.
        $this->actingAs($this->admin, 'sanctum')->getJson("{$base}/{$otherBreakout->id}")->assertNotFound();
    }

    public function test_xlsx_has_overview_and_one_sheet_per_breakout_after_the_sessions(): void
    {
        $this->seedBreakoutData();
        EventSession::factory()->for($this->event)->create(['session_date' => '2026-06-25']);
        $second = Breakout::factory()->for($this->event)->create(['title' => 'Music', 'date' => '2026-06-25', 'start_time' => '11:00', 'end_time' => '12:00']);

        $spreadsheet = $this->load(new EventAttendanceExport($this->event));
        $names = $spreadsheet->getSheetNames();

        $this->assertCount(5, $names);
        $this->assertSame('All Sessions', $names[0]);
        $this->assertSame('Breakouts', $names[2]);
        $this->assertStringStartsWith("B{$this->breakout->id} Youth Track", $names[3]);
        $this->assertStringStartsWith("B{$second->id} Music", $names[4]);

        $overview = $spreadsheet->getSheetByName('Breakouts');
        $this->assertSame('Youth Track', $overview->getCell('A2')->getValue());
        $this->assertSame('Yes', $overview->getCell('H2')->getValue());
        $this->assertSame(1, (int) $overview->getCell('L2')->getValue());
        $roster = $spreadsheet->getSheet(3);
        $this->assertSame(4, $roster->getHighestRow() - 1);
        $statuses = collect(range(2, 5))->map(fn ($r) => $roster->getCell("E{$r}")->getValue())->sort()->values()->all();
        $this->assertSame(['Absent', 'Absent', 'Present', 'Walk-in'], $statuses);
    }

    public function test_event_without_breakouts_exports_only_session_sheets(): void
    {
        $event = Event::factory()->for($this->org)->create();
        EventSession::factory()->for($event)->create(['session_date' => '2026-06-25']);

        $this->assertCount(2, $this->load(new EventAttendanceExport($event))->getSheetNames());
    }

    public function test_session_sheet_shows_the_via_breakout_column_and_via_check_ins_stay_present(): void
    {
        $session = EventSession::factory()->for($this->event)->create(['session_date' => '2026-06-25', 'start_time' => '08:30', 'end_time' => '10:30']);
        $attendee = $this->attendee();
        AttendanceRecord::create([
            'attendee_id' => $attendee->id, 'event_session_id' => $session->id, 'via_breakout_id' => $this->breakout->id,
            'check_in_at' => '2026-06-25 09:05:00', 'method' => 'qr', 'recorded_by' => $this->admin->id,
        ]);
        $this->attendee(false);

        $sheet = $this->load(new EventAttendanceExport($this->event, $session))->getSheet(0);

        $this->assertSame('Via Breakout', $sheet->getCell('J1')->getValue());
        $rows = collect(range(2, $sheet->getHighestRow()))->mapWithKeys(fn ($r) => [$sheet->getCell("J{$r}")->getValue() => $sheet->getCell("E{$r}")->getValue()]);
        $this->assertSame('Checked In', $rows['Youth Track']);
        $this->assertSame('Absent', $rows['']);
    }

    public function test_session_roster_endpoint_exposes_via_breakout(): void
    {
        $session = EventSession::factory()->for($this->event)->create(['session_date' => '2026-06-25', 'start_time' => '08:30', 'end_time' => '10:30']);
        $attendee = $this->attendee();
        AttendanceRecord::create([
            'attendee_id' => $attendee->id, 'event_session_id' => $session->id, 'via_breakout_id' => $this->breakout->id,
            'check_in_at' => '2026-06-25 09:05:00', 'method' => 'qr', 'recorded_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/events/{$this->event->id}/sessions/{$session->id}/roster")
            ->assertOk()
            ->assertJsonPath('data.0.attendance_status', 'Present')
            ->assertJsonPath('data.0.via_breakout.title', 'Youth Track');
    }

    public function test_pdf_report_includes_breakout_sections_and_audits_the_export(): void
    {
        $this->seedBreakoutData();

        $this->actingAs($this->admin, 'sanctum')->get("/api/v1/events/{$this->event->id}/reports/attendance-summary/export?format=pdf&breakout_id={$this->breakout->id}")
            ->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->assertDatabaseHas('audit_logs', ['action' => 'breakout_attendance.exported']);
    }

    public function test_breakout_exports_are_audited_but_session_only_exports_are_not(): void
    {
        $session = EventSession::factory()->for($this->event)->create(['session_date' => '2026-06-25']);
        $url = "/api/v1/events/{$this->event->id}/reports/attendance-summary/export";

        $this->actingAs($this->admin, 'sanctum')->get("{$url}?session_id={$session->id}")->assertOk();
        $this->assertSame(0, AuditLog::where('action', 'breakout_attendance.exported')->count());

        $this->actingAs($this->admin, 'sanctum')->get($url)->assertOk();
        $this->actingAs($this->admin, 'sanctum')->get("{$url}?breakout_id={$this->breakout->id}")->assertOk();
        $this->assertSame(2, AuditLog::where('action', 'breakout_attendance.exported')->count());
    }

    public function test_breakout_export_stays_behind_view_staff_data(): void
    {
        $outsider = User::factory()->orgAdmin()->for(Organization::factory()->create())->create();

        $this->actingAs($outsider, 'sanctum')->get("/api/v1/events/{$this->event->id}/reports/attendance-summary/export?breakout_id={$this->breakout->id}")->assertNotFound();
    }

    public function test_attendee_summary_adds_breakout_columns_and_keeps_the_session_rating_sessions_only(): void
    {
        $session = EventSession::factory()->for($this->event)->create(['session_date' => '2026-06-25', 'start_time' => '08:00', 'end_time' => '08:30']);
        $present = $this->attendee();
        $this->checkIn($present);
        AttendanceRecord::create(['attendee_id' => $present->id, 'event_session_id' => $session->id, 'check_in_at' => '2026-06-25 08:05:00', 'method' => 'qr', 'recorded_by' => $this->admin->id]);
        $absent = $this->attendee();

        $rows = collect($this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/events/{$this->event->id}/reports/attendee-summary")->assertOk()->json('data'))->keyBy('attendee_id');

        $this->assertSame(1, $rows[$present->id]['present']);
        $this->assertSame(1, $rows[$present->id]['breakout_present']);
        $this->assertSame(0, $rows[$present->id]['breakout_absent']);
        $this->assertSame(100, (int) $rows[$present->id]['attendance_rating']);
        $this->assertSame(0, $rows[$absent->id]['present']);
        $this->assertSame(1, $rows[$absent->id]['absent']);
        $this->assertSame(1, $rows[$absent->id]['breakout_absent']);

        $sheet = $this->load(new AttendeeSummaryExport(collect($rows->values()), true))->getSheet(0);
        $this->assertSame(['Breakout Present', 'Breakout Absent'], [$sheet->getCell('F1')->getValue(), $sheet->getCell('G1')->getValue()]);
    }

    private function breakoutWithAttendanceOff(): Breakout
    {
        $off = Breakout::factory()->for($this->event)->create([
            'title' => 'Informal Chat', 'date' => '2026-06-25', 'start_time' => '09:00', 'end_time' => '10:00', 'attendance_enabled' => false,
        ]);
        $pick = $this->attendee(false);
        $pick->breakouts()->attach($off->id);
        $this->checkIn($pick, [], $off);
        $this->checkIn($this->attendee(false), ['is_walk_in' => true, 'method' => 'manual'], $off);

        return $off;
    }

    public function test_report_overview_leaves_out_breakouts_with_attendance_off(): void
    {
        $this->breakoutWithAttendanceOff();

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/events/{$this->event->id}/reports/breakouts")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.breakout_id', $this->breakout->id);
    }

    public function test_roster_of_a_breakout_with_attendance_off_shows_no_attendance(): void
    {
        $off = $this->breakoutWithAttendanceOff();

        $rows = $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/events/{$this->event->id}/reports/breakouts/{$off->id}")
            ->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['status']);
        $this->assertNull($rows[0]['check_in_at']);
        $this->assertNull($rows[0]['method']);
        $this->assertNull($rows[0]['recorded_by']);
    }

    public function test_full_xlsx_has_no_overview_row_or_sheet_for_breakouts_with_attendance_off(): void
    {
        $this->seedBreakoutData();
        $off = $this->breakoutWithAttendanceOff();

        $spreadsheet = $this->load(new EventAttendanceExport($this->event));
        $names = $spreadsheet->getSheetNames();

        $this->assertCount(3, $names);
        $this->assertStringStartsWith("B{$this->breakout->id} Youth Track", $names[2]);
        $overview = $spreadsheet->getSheetByName('Breakouts');
        $this->assertSame(2, $overview->getHighestRow());
        $this->assertSame('Youth Track', $overview->getCell('A2')->getValue());
        $this->assertNotContains('Informal Chat', collect($names)->map(fn ($n) => substr($n, strlen("B{$off->id} ")))->all());
    }

    public function test_event_with_only_breakouts_off_exports_no_breakout_sheets(): void
    {
        $event = Event::factory()->for($this->org)->create();
        Breakout::factory()->for($event)->create(['attendance_enabled' => false]);

        $this->assertNull($this->load(new EventAttendanceExport($event))->getSheetByName('Breakouts'));
    }

    public function test_full_pdf_export_succeeds_without_breakouts_with_attendance_off(): void
    {
        $this->breakoutWithAttendanceOff();

        $this->actingAs($this->admin, 'sanctum')->get("/api/v1/events/{$this->event->id}/reports/attendance-summary/export?format=pdf")
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_single_breakout_export_is_refused_for_a_breakout_with_attendance_off(): void
    {
        $off = $this->breakoutWithAttendanceOff();
        $url = "/api/v1/events/{$this->event->id}/reports/attendance-summary/export?breakout_id={$off->id}";

        $this->actingAs($this->admin, 'sanctum')->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('breakout_id');
        $this->actingAs($this->admin, 'sanctum')->getJson("{$url}&format=pdf")->assertUnprocessable()->assertJsonValidationErrors('breakout_id');
    }

    public function test_attendee_summary_breakout_counts_ignore_breakouts_with_attendance_off(): void
    {
        $off = $this->breakoutWithAttendanceOff();
        $session = EventSession::factory()->for($this->event)->create(['session_date' => '2026-06-25', 'start_time' => '08:00', 'end_time' => '08:30']);
        $attendee = $this->attendee(false);
        $attendee->breakouts()->attach($off->id);
        $this->checkIn($attendee, [], $off);
        $absent = $this->attendee(false);
        $absent->breakouts()->attach($off->id);
        $this->checkIn($this->attendee(), [], $this->breakout);

        $rows = collect($this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/events/{$this->event->id}/reports/attendee-summary")->assertOk()->json('data'))->keyBy('attendee_id');

        $this->assertSame(0, $rows[$attendee->id]['breakout_present']);
        $this->assertSame(0, $rows[$absent->id]['breakout_absent']);
        $this->assertSame(1, $rows[$attendee->id]['absent']);
    }

    public function test_turning_attendance_back_on_restores_earlier_check_ins(): void
    {
        $off = $this->breakoutWithAttendanceOff();
        $url = "/api/v1/events/{$this->event->id}/reports/breakouts";

        $off->update(['attendance_enabled' => true]);

        $row = collect($this->actingAs($this->admin, 'sanctum')->getJson($url)->json('data'))->firstWhere('breakout_id', $off->id);
        $this->assertSame(2, $row['present']);
        $this->assertSame(1, $row['walk_ins']);
    }

    public function test_attendee_summary_export_without_breakouts_is_unchanged(): void
    {
        $event = Event::factory()->for($this->org)->create(['status' => 'published']);
        EventSession::factory()->for($event)->create(['session_date' => '2026-06-25', 'start_time' => '08:00', 'end_time' => '08:30']);
        Attendee::factory()->for($event)->for($this->org)->create(['first_registered_at' => '2026-06-20 08:00:00']);

        $this->actingAs($this->admin, 'sanctum')->get("/api/v1/events/{$event->id}/reports/attendee-summary/export")->assertOk();
        $sheet = $this->load(new AttendeeSummaryExport(collect([[
            'attendee_name' => 'A B', 'organization_name' => null, 'present' => 0, 'absent' => 1, 'remaining' => 0, 'attendance_rating' => 0,
            'breakout_present' => 0, 'breakout_absent' => 0,
        ]])))->getSheet(0);

        $this->assertSame(['Attendee', 'Organization Level', 'Present', 'Absent', 'Attendance Rating'], collect(range('A', 'E'))->map(fn ($c) => $sheet->getCell("{$c}1")->getValue())->all());
        $this->assertNull($sheet->getCell('F1')->getValue());
    }

    private function load(object $export): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'ams-export-');
        file_put_contents($path, Excel::raw($export, ExcelWriter::XLSX));
        try {
            return IOFactory::load($path);
        } finally {
            unlink($path);
        }
    }
}
