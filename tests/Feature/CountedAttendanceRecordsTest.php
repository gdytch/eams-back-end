<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\Breakout;
use App\Models\Event;
use App\Models\EventSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CountedAttendanceRecordsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function breakoutRecord(Breakout $breakout): AttendanceRecord
    {
        return AttendanceRecord::factory()->create([
            'attendee_id' => Attendee::factory()->create()->id,
            'event_session_id' => null,
            'breakout_id' => $breakout->id,
        ]);
    }

    public function test_counted_excludes_records_of_breakouts_with_attendance_off_and_restores_them_when_on_again(): void
    {
        $event = Event::factory()->create();
        $on = $this->breakoutRecord(Breakout::factory()->for($event)->create());
        $offBreakout = Breakout::factory()->for($event)->create(['attendance_enabled' => false]);
        $off = $this->breakoutRecord($offBreakout);

        $this->assertEqualsCanonicalizing([$on->id], AttendanceRecord::counted()->pluck('id')->all());

        $offBreakout->update(['attendance_enabled' => true]);
        $this->assertEqualsCanonicalizing([$on->id, $off->id], AttendanceRecord::counted()->pluck('id')->all());
        $this->assertSame(2, AttendanceRecord::count());
    }

    public function test_cascaded_session_records_always_count(): void
    {
        $event = Event::factory()->create();
        $breakout = Breakout::factory()->for($event)->create(['attendance_enabled' => false]);
        $session = EventSession::factory()->for($event)->create();
        $cascaded = AttendanceRecord::factory()->create([
            'attendee_id' => Attendee::factory()->create()->id,
            'event_session_id' => $session->id,
            'via_breakout_id' => $breakout->id,
        ]);

        $this->assertSame([$cascaded->id], AttendanceRecord::counted()->pluck('id')->all());
    }
}
