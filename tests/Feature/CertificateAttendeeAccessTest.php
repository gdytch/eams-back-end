<?php

namespace Tests\Feature;

use App\Enums\OrganizationLevel;
use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\Breakout;
use App\Models\Certificate;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\IssuedCertificate;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CertificateAttendeeAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $org;

    private Event $event;

    private User $user;

    private Attendee $attendee;

    private Certificate $certificate;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-06-10 12:00:00');

        $this->org = Organization::factory()->create();
        $this->event = Event::factory()->for($this->org)->create();
        $this->user = User::factory()->attendee()->create();
        $this->attendee = $this->register($this->event, $this->user);
        $this->certificate = $this->publishedCertificate($this->event);
        $this->issue($this->certificate, $this->attendee);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function register(Event $event, ?User $user, string $registeredAt = '2026-06-01 08:00:00'): Attendee
    {
        $attendee = Attendee::factory()->for($event)->create(['user_id' => $user?->id]);
        $attendee->forceFill(['first_registered_at' => $registeredAt])->saveQuietly();

        return $attendee;
    }

    private function publishedCertificate(Event $event, array $attributes = []): Certificate
    {
        return Certificate::factory()->for($event)->create([
            'status' => 'published', 'published_at' => '2026-06-01 00:00:00', 'release_at' => '2026-06-10 09:00:00',
            'release_rule' => 'everyone', ...$attributes,
        ]);
    }

    private function issue(Certificate $certificate, Attendee $attendee, string $content = 'PDF'): IssuedCertificate
    {
        $path = "certificates/{$certificate->id}/{$attendee->id}.pdf";
        Storage::disk('local')->put($path, $content);

        return IssuedCertificate::create([
            'certificate_id' => $certificate->id, 'attendee_id' => $attendee->id,
            'status' => 'generated', 'file_path' => $path, 'generated_at' => now(),
        ]);
    }

    private function makeSession(string $date, ?Event $event = null): EventSession
    {
        return EventSession::factory()->for($event ?? $this->event)->create([
            'session_date' => $date, 'start_time' => '09:00:00', 'end_time' => '11:00:00',
        ]);
    }

    private function breakout(string $date, ?Event $event = null): Breakout
    {
        return Breakout::factory()->for($event ?? $this->event)->create(['date' => $date, 'start_time' => '14:00:00', 'end_time' => '15:00:00']);
    }

    private function checkIn(Attendee $attendee, EventSession|Breakout $slot, array $extra = []): void
    {
        AttendanceRecord::create([
            'attendee_id' => $attendee->id, AttendanceRecord::slotColumn($slot) => $slot->id,
            'check_in_at' => '2026-06-09 09:30:00', 'method' => 'manual', ...$extra,
        ])->save();
    }

    private function listed(?User $as = null): array
    {
        return $this->actingAs($as ?? $this->user, 'sanctum')
            ->getJson("/api/v1/attendee/events/{$this->event->id}/certificates")->assertOk()->json('data');
    }

    private function download(?Certificate $certificate = null, ?User $as = null): TestResponse
    {
        $certificate ??= $this->certificate;

        return $this->actingAs($as ?? $this->user, 'sanctum')
            ->get("/api/v1/attendee/events/{$this->event->id}/certificates/{$certificate->id}/download");
    }

    private function assertAccess(bool $allowed): void
    {
        $this->assertCount($allowed ? 1 : 0, $this->listed());
        $this->download()->assertStatus($allowed ? 200 : 404);
    }

    private function rule(string $rule, bool $breakouts = false): void
    {
        $this->certificate->update(['release_rule' => $rule, 'include_breakouts' => $breakouts]);
    }

    public function test_released_certificate_is_listed_and_downloads_own_file(): void
    {
        $rows = $this->listed();

        $this->assertSame([$this->certificate->id], array_column($rows, 'id'));
        $this->assertSame($this->certificate->title, $rows[0]['title']);
        $response = $this->download()->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('PDF', $response->streamedContent());
    }

    public function test_nothing_before_the_release_time(): void
    {
        $this->certificate->update(['release_at' => '2026-06-10 12:00:01']);
        $this->assertAccess(false);

        Carbon::setTestNow('2026-06-10 12:00:01');
        $this->assertAccess(true);
    }

    public function test_null_release_time_means_released_once_published(): void
    {
        $this->certificate->update(['release_at' => null]);
        $this->assertAccess(true);

        $this->certificate->update(['status' => 'draft']);
        $this->assertAccess(false);
    }

    public function test_checked_in_rule_needs_a_session_check_in(): void
    {
        $this->rule('checked_in');
        $session = $this->makeSession('2026-06-09');
        $this->assertAccess(false);

        $this->checkIn($this->attendee, $session);
        $this->assertAccess(true);
    }

    public function test_checked_in_counts_breakouts_only_with_the_flag(): void
    {
        $this->rule('checked_in');
        $breakout = $this->breakout('2026-06-09');
        $this->checkIn($this->attendee, $breakout);
        $this->assertAccess(false);

        $this->rule('checked_in', true);
        $this->assertAccess(true);
    }

    public function test_a_session_check_in_made_through_a_breakout_is_session_attendance_for_every_rule(): void
    {
        $breakout = $this->breakout('2026-06-09');
        $session = $this->makeSession('2026-06-09');
        $this->checkIn($this->attendee, $session, ['via_breakout_id' => $breakout->id]);

        // The attendance report shows it as a present session, so the checked_in and the rate rules agree.
        foreach (['checked_in', 'rate_50', 'rate_75'] as $rule) {
            $this->rule($rule);
            $this->assertAccess(true);
        }
    }

    public function test_access_follows_attendance_immediately(): void
    {
        $this->rule('checked_in');
        $session = $this->makeSession('2026-06-09');
        $this->assertAccess(false);

        $this->checkIn($this->attendee, $session);

        $this->assertAccess(true);
    }

    public function test_rate_rules_qualify_at_exactly_50_and_75_percent(): void
    {
        $sessions = collect(['2026-06-06', '2026-06-07', '2026-06-08', '2026-06-09'])->map(fn ($d) => $this->makeSession($d));
        $this->makeSession('2026-06-11'); // Upcoming: not counted yet.

        $this->rule('rate_50');
        $this->checkIn($this->attendee, $sessions[0]);
        $this->assertAccess(false); // 25%
        $this->checkIn($this->attendee, $sessions[1]);
        $this->assertAccess(true); // exactly 50%

        $this->rule('rate_75');
        $this->assertAccess(false);
        $this->checkIn($this->attendee, $sessions[2]);
        $this->assertAccess(true); // exactly 75%
    }

    public function test_rate_with_no_ended_expected_slots_is_not_eligible(): void
    {
        $this->rule('rate_50');
        $this->makeSession('2026-06-11');
        $this->assertAccess(false);
    }

    public function test_walk_ins_only_add_to_the_numerator_and_are_capped_at_100_percent(): void
    {
        $this->rule('rate_75');
        $this->attendee->forceFill(['first_registered_at' => '2026-06-08 10:00:00'])->saveQuietly();
        $beforeRegistering = $this->makeSession('2026-06-06'); // ended before registering: not Expected
        $expectedAbsent = $this->makeSession('2026-06-09');
        $expectedAbsent2 = $this->makeSession('2026-06-09');

        $this->checkIn($this->attendee, $beforeRegistering, ['is_walk_in' => true]);
        $this->assertAccess(false); // 1 present over 2 expected = 50%

        $this->checkIn($this->attendee, $expectedAbsent);
        $this->assertAccess(true); // 2 present over 2 expected = 100%
        $this->checkIn($this->attendee, $expectedAbsent2);
        $this->assertAccess(true); // 3 present over 2 expected, capped
    }

    public function test_breakouts_count_in_the_rate_only_with_the_flag(): void
    {
        $sessions = [$this->makeSession('2026-06-08'), $this->makeSession('2026-06-09')];
        $breakout = $this->breakout('2026-06-09');
        $this->attendee->breakouts()->attach($breakout->id);
        foreach ($sessions as $session) {
            $this->checkIn($this->attendee, $session);
        }

        $this->rule('rate_75', false);
        $this->assertAccess(true); // 2 of 2 sessions

        $this->rule('rate_75', true);
        $this->assertAccess(false); // 2 of 3 slots = 66.7%

        $this->rule('rate_50', true);
        $this->assertAccess(true);
    }

    public function test_breakout_not_picked_is_not_expected(): void
    {
        $this->rule('rate_75', true);
        $this->checkIn($this->attendee, $this->makeSession('2026-06-09'));
        $this->breakout('2026-06-09');

        $this->assertAccess(true);
    }

    public function test_hand_picked_attendee_is_eligible_whatever_the_rule_once_released(): void
    {
        $this->rule('rate_75');
        $this->makeSession('2026-06-09');
        $this->assertAccess(false);

        $this->certificate->update(['audience_attendee_ids' => [$this->attendee->id]]);
        $this->assertAccess(true);

        $this->certificate->update(['release_at' => '2026-06-11 00:00:00']);
        $this->assertAccess(false);
    }

    public function test_attendee_who_left_the_audience_is_hidden(): void
    {
        $union = Union::factory()->create(['organization_id' => $this->org->id]);
        $this->certificate->update(['audience_union_ids' => [$union->id]]);
        $this->assertAccess(false);

        $this->attendee->forceFill(['organization_level' => OrganizationLevel::Union, 'union_id' => $union->id])->saveQuietly();
        $this->assertAccess(true);
    }

    public function test_incompletely_registered_attendee_gets_nothing_on_the_everyone_rule(): void
    {
        $this->attendee->forceFill(['registration_completed_at' => null])->saveQuietly();
        $this->assertAccess(false);
    }

    public function test_only_generated_files_that_still_exist_are_returned(): void
    {
        $issued = $this->certificate->issuedCertificates()->first();

        $issued->update(['status' => 'failed']);
        $this->assertAccess(false);

        $issued->update(['status' => 'generated', 'file_deleted_at' => now()]);
        $this->assertAccess(false);

        $issued->update(['file_deleted_at' => null]);
        Storage::disk('local')->delete($issued->file_path);
        $this->download()->assertNotFound();
    }

    public function test_attendee_never_reads_another_attendees_file(): void
    {
        $other = $this->register($this->event, User::factory()->attendee()->create());
        $secret = $this->publishedCertificate($this->event, ['title' => 'Other only']);
        $this->issue($secret, $other, 'SECRET');

        $this->assertSame([$this->certificate->id], array_column($this->listed(), 'id'));
        $this->download($secret)->assertNotFound();

        $this->assertSame('SECRET', $this->download($secret, $other->user)->assertOk()->streamedContent());
        $this->download($this->certificate, $other->user)->assertNotFound();
    }

    public function test_certificate_of_another_event_is_not_reachable_through_this_event(): void
    {
        $otherEvent = Event::factory()->for($this->org)->create();
        $otherAttendee = $this->register($otherEvent, $this->user);
        $foreign = $this->publishedCertificate($otherEvent);
        $this->issue($foreign, $otherAttendee);

        $this->assertSame([$this->certificate->id], array_column($this->listed(), 'id'));
        $this->download($foreign)->assertNotFound();
    }

    public function test_user_without_a_registration_in_the_event_gets_nothing(): void
    {
        $stranger = User::factory()->attendee()->create();

        $this->assertSame([], $this->listed($stranger));
        $this->download(null, $stranger)->assertNotFound();
    }

    public function test_only_attendee_accounts_may_use_the_routes(): void
    {
        $admin = User::factory()->orgAdmin()->for($this->org)->create();

        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/attendee/events/{$this->event->id}/certificates")->assertForbidden();
        $this->download(null, $admin)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/attendee/events/{$this->event->id}/certificates")->assertUnauthorized();
    }

    public function test_archived_event_files_stay_downloadable(): void
    {
        $this->event->forceFill(['archived_at' => now()])->saveQuietly();

        $this->assertAccess(true);
    }
}
