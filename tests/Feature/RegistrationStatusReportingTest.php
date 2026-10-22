<?php

namespace Tests\Feature;

use App\Exports\AttendeeExport;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\Mission;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class RegistrationStatusReportingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $org;

    private User $admin;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->orgAdmin()->for($this->org)->create();
        $this->event = Event::factory()->for($this->org)->create([
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
        ]);
    }

    /** @return array{registered: Attendee, incomplete: Attendee, draft: Attendee} */
    private function seedAttendees(): array
    {
        $registered = Attendee::factory()->for($this->event)->create();
        $incomplete = Attendee::factory()->for($this->event)->create();
        $incomplete->update([
            'organization_level' => 'mission',
            'mission_id' => Mission::factory()->for($this->org)->create(['union_id' => $incomplete->union_id])->id,
        ]);
        $draft = Attendee::factory()->for($this->event)->draft()->create(['mobile_no' => null]);

        return compact('registered', 'incomplete', 'draft');
    }

    public function test_draft_dashboard_has_finish_banner_and_no_qr(): void
    {
        $user = User::factory()->attendee()->create(['organization_id' => $this->org->id]);
        $draft = Attendee::factory()->for($this->event)->draft()->create(['user_id' => $user->id]);
        $draft->update(['church_id' => null, 'organization_level' => 'mission']);

        $row = $this->actingAs($user, 'sanctum')->getJson('/api/v1/attendee/dashboard')
            ->assertOk()->json('data.upcoming_events.0');

        $this->assertNull($row['qr_token']);
        $this->assertNull($row['id_card_url']);
        $this->assertSame('draft', $row['banner']['status']);
        $this->assertFalse($row['banner']['qr_and_id_card_work']);
        $this->assertContains('Add your church', $row['banner']['missing']);
    }

    public function test_incomplete_dashboard_names_missing_items_and_keeps_qr(): void
    {
        $user = User::factory()->attendee()->create(['organization_id' => $this->org->id]);
        $attendee = Attendee::factory()->for($this->event)->create(['user_id' => $user->id]);
        $attendee->update(['organization_level' => 'mission']);

        $row = $this->actingAs($user, 'sanctum')->getJson('/api/v1/attendee/dashboard')->json('data.upcoming_events.0');

        $this->assertNotNull($row['qr_token']);
        $this->assertSame('incomplete', $row['banner']['status']);
        $this->assertTrue($row['banner']['qr_and_id_card_work']);
        $this->assertContains('Add your church', $row['banner']['missing']);
    }

    public function test_fully_registered_dashboard_has_no_banner(): void
    {
        $user = User::factory()->attendee()->create(['organization_id' => $this->org->id]);
        Attendee::factory()->for($this->event)->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/attendee/dashboard')
            ->assertJsonPath('data.upcoming_events.0.banner', null);
    }

    public function test_event_dashboard_splits_incomplete_and_leaves_drafts_out_of_the_roster(): void
    {
        $this->seedAttendees();

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/events/{$this->event->id}/reports/dashboard")
            ->assertOk()
            ->assertJsonPath('incomplete.draft', 1)
            ->assertJsonPath('incomplete.once_registered', 1)
            ->assertJsonPath('attendance.registered', 2);
    }

    public function test_attendee_list_hides_drafts_unless_filtered(): void
    {
        $rows = $this->seedAttendees();
        $url = "/api/v1/attendees?event_id={$this->event->id}";

        $ids = collect($this->actingAs($this->admin, 'sanctum')->getJson($url)->json('data'))->pluck('id');
        $this->assertEqualsCanonicalizing([$rows['registered']->id, $rows['incomplete']->id], $ids->all());

        $draft = $this->getJson("$url&registration_status=draft")->json('data');
        $this->assertSame([$rows['draft']->id], collect($draft)->pluck('id')->all());
        $this->assertSame('draft', $draft[0]['registration_status']);

        $this->getJson("$url&registration_status=incomplete")->assertJsonPath('data.0.id', $rows['incomplete']->id)->assertJsonCount(1, 'data');
        $this->getJson("$url&registration_status=nope")->assertUnprocessable();
    }

    public function test_export_includes_everyone_with_a_status_column_and_accepts_the_filter(): void
    {
        $this->seedAttendees();
        $url = "/api/v1/attendees/export?event_id={$this->event->id}";

        $this->actingAs($this->admin, 'sanctum');
        Excel::fake();
        $this->get($url)->assertOk();
        Excel::assertDownloaded('attendees.xlsx', function (AttendeeExport $export) {
            $this->assertSame('Registration Status', $export->headings()[7]);
            $this->assertEqualsCanonicalizing(['Fully registered', 'Incomplete', 'Draft'], $export->collection()->pluck('Registration Status')->all());

            return true;
        });

        Excel::fake();
        $this->get("$url&registration_status=draft")->assertOk();
        Excel::assertDownloaded('attendees.xlsx', fn (AttendeeExport $export) => $export->collection()->pluck('Registration Status')->all() === ['Draft']);
    }
}
