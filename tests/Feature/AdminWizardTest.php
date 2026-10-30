<?php

namespace Tests\Feature;

use App\Jobs\GenerateAttendeeIdCardJob;
use App\Mail\AttendeeAccountInviteMail;
use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminWizardTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $org;

    private User $admin;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->orgAdmin()->for($this->org)->create();
        $this->event = Event::factory()->for($this->org)->create();
    }

    private function basicInfo(array $extra = []): array
    {
        return ['event_id' => $this->event->id, 'first_name' => 'Ana', 'last_name' => 'Reyes', 'mobile_no' => '09170000000', ...$extra];
    }

    private function territory(): array
    {
        return ['organization_level' => 'union', 'union_id' => Union::factory()->for($this->org)->create()->id];
    }

    public function test_admin_creates_a_draft_without_a_user_and_email_is_optional(): void
    {
        $id = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/attendees', $this->basicInfo())
            ->assertCreated()->json('data.id');

        $this->assertDatabaseHas('attendees', ['id' => $id, 'user_id' => null, 'registered_by' => $this->admin->id, 'registration_completed_at' => null]);
        $this->assertSame(0, User::where('first_name', 'Ana')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendee.created', 'user_id' => $this->admin->id]);
    }

    public function test_finish_without_email_registers_and_issues_a_qr(): void
    {
        $attendee = Attendee::find($this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/attendees', $this->basicInfo())->json('data.id'));

        $this->postJson("/api/v1/attendees/{$attendee->id}/finish")->assertUnprocessable()->assertJsonValidationErrors('steps');
        $this->assertNull($attendee->refresh()->registration_confirmed_at);

        $this->putJson("/api/v1/attendees/{$attendee->id}", $this->territory())->assertOk();
        $this->postJson("/api/v1/attendees/{$attendee->id}/finish")->assertOk();

        $attendee->refresh();
        $this->assertNotNull($attendee->first_registered_at);
        $this->assertNotNull($attendee->qr_token);
        Queue::assertPushed(GenerateAttendeeIdCardJob::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'event_registration.finished', 'auditable_id' => $attendee->id]);
    }

    public function test_email_of_a_user_links_and_prefills_from_their_latest_attendee(): void
    {
        $user = User::factory()->attendee()->create(['email' => 'link@example.com']);
        $union = Union::factory()->for($this->org)->create();
        Attendee::factory()->for(Event::factory()->for($this->org)->create())->create([
            'user_id' => $user->id, 'email_address' => $user->email, 'mobile_no' => '09999999999',
            'organization_level' => 'union', 'union_id' => $union->id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/attendees', $this->basicInfo(['email_address' => 'LINK@example.com', 'mobile_no' => null]))
            ->assertCreated();

        $this->assertDatabaseHas('attendees', [
            'id' => $response->json('data.id'), 'user_id' => $user->id, 'email_address' => 'link@example.com',
            'registered_by' => $this->admin->id, 'union_id' => $union->id,
        ]);
        $this->assertNull(Attendee::find($response->json('data.id'))->registration_confirmed_at);
    }

    public function test_email_of_a_user_with_an_attendee_in_the_event_returns_that_attendee(): void
    {
        $user = User::factory()->attendee()->create(['email' => 'has@example.com']);
        $existing = Attendee::factory()->for($this->event)->create(['user_id' => $user->id, 'email_address' => $user->email]);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/attendees', $this->basicInfo(['email_address' => 'has@example.com']))
            ->assertOk()->assertJsonPath('data.id', $existing->id);

        $this->assertSame(1, Attendee::where('event_id', $this->event->id)->where('user_id', $user->id)->count());
    }

    public function test_duplicate_name_warns_in_the_same_event_only_and_confirm_overrides(): void
    {
        Attendee::factory()->for($this->event)->create(['first_name' => 'Ana', 'last_name' => 'Reyes']);
        Attendee::factory()->for(Event::factory()->for($this->org)->create())->create(['first_name' => 'Bea', 'last_name' => 'Cruz']);

        $this->actingAs($this->admin, 'sanctum');
        $this->postJson('/api/v1/attendees', $this->basicInfo())->assertStatus(409)->assertJsonCount(1, 'duplicates');
        $this->postJson('/api/v1/attendees', $this->basicInfo(['first_name' => 'Bea', 'last_name' => 'Cruz']))->assertCreated();
        $this->postJson('/api/v1/attendees', $this->basicInfo(['confirm' => true]))->assertCreated();
    }

    public function test_a_draft_and_a_registered_attendee_can_be_deleted_and_it_is_audited(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $draft = Attendee::find($this->postJson('/api/v1/attendees', $this->basicInfo())->json('data.id'));
        $registered = Attendee::factory()->for($this->event)->create(['first_registered_at' => now()]);
        $session = EventSession::factory()->for($this->event)->create();
        AttendanceRecord::factory()->for($registered)->for($session, 'eventSession')->create();

        $this->deleteJson("/api/v1/attendees/{$registered->id}")->assertNoContent();
        $this->assertDatabaseMissing('attendees', ['id' => $registered->id]);
        $this->assertDatabaseMissing('attendance_records', ['attendee_id' => $registered->id]);
        $log = AuditLog::where('action', 'attendee.deleted')->where('auditable_id', $registered->id)->sole();
        $this->assertSame($registered->last_name, $log->changes['snapshot']['last_name']);

        $this->deleteJson("/api/v1/attendees/{$draft->id}")->assertNoContent();
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendee.deleted', 'auditable_id' => $draft->id]);
    }

    public function test_resend_invite_issues_a_fresh_token_and_is_audited(): void
    {
        $attendee = Attendee::factory()->for($this->event)->create([
            'user_id' => null, 'email_address' => 'inv@example.com', 'invite_token' => 'old-token', 'invited_at' => now()->subDays(40),
        ]);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/attendees/{$attendee->id}/resend-invite")->assertOk();

        $attendee->refresh();
        $this->assertNotSame('old-token', $attendee->invite_token);
        $this->assertTrue($attendee->invited_at->isAfter(now()->subMinute()));
        Mail::assertQueued(AttendeeAccountInviteMail::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendee.invite_resent', 'auditable_id' => $attendee->id]);
    }

    public function test_resend_invite_needs_an_email_and_no_login(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $noEmail = Attendee::factory()->for($this->event)->create(['user_id' => null, 'email_address' => null]);
        $linked = Attendee::factory()->for($this->event)->create(['user_id' => User::factory()->attendee()->create()->id, 'email_address' => 'x@example.com']);

        $this->postJson("/api/v1/attendees/{$noEmail->id}/resend-invite")->assertUnprocessable();
        $this->postJson("/api/v1/attendees/{$linked->id}/resend-invite")->assertUnprocessable();
        Mail::assertNothingQueued();
    }

    public function test_checker_gets_403_on_every_admin_wizard_endpoint(): void
    {
        $checker = User::factory()->checker()->for($this->org)->create();
        $attendee = Attendee::factory()->for($this->event)->create(['user_id' => null, 'email_address' => 'c@example.com']);

        $this->actingAs($checker, 'sanctum');
        $this->postJson('/api/v1/attendees', $this->basicInfo())->assertForbidden();
        $this->postJson("/api/v1/attendees/{$attendee->id}/finish")->assertForbidden();
        $this->postJson("/api/v1/attendees/{$attendee->id}/resend-invite")->assertForbidden();
        $this->deleteJson("/api/v1/attendees/{$attendee->id}")->assertForbidden();
    }

    public function test_an_email_of_a_staff_user_is_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/attendees', $this->basicInfo(['email_address' => $this->admin->email]))
            ->assertUnprocessable()->assertJsonValidationErrors('email_address');
    }

    public function test_an_unverified_account_with_the_email_is_not_linked(): void
    {
        $user = User::factory()->attendee()->unverified()->create(['email' => 'squat@example.com']);

        $id = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/attendees', $this->basicInfo(['email_address' => 'squat@example.com']))
            ->assertCreated()->json('data.id');

        $this->assertDatabaseHas('attendees', ['id' => $id, 'user_id' => null, 'email_address' => 'squat@example.com']);
        $this->assertNotSame($user->id, Attendee::find($id)->user_id);
    }

    public function test_prefill_ignores_the_users_attendees_in_other_organizations(): void
    {
        $user = User::factory()->attendee()->create(['email' => 'cross@example.com']);
        Attendee::factory()->for(Event::factory()->for(Organization::factory()->create())->create())->create([
            'user_id' => $user->id, 'email_address' => $user->email, 'remarks' => 'secret elsewhere',
        ]);

        $id = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/attendees', $this->basicInfo(['email_address' => 'cross@example.com']))
            ->assertCreated()->json('data.id');

        $this->assertNull(Attendee::find($id)->remarks);
    }

    public function test_other_organization_admin_and_archived_events_are_blocked(): void
    {
        $attendee = Attendee::factory()->for($this->event)->create(['user_id' => null, 'email_address' => 'o@example.com']);
        $other = User::factory()->orgAdmin()->for(Organization::factory()->create())->create();

        $this->actingAs($other, 'sanctum')->postJson("/api/v1/attendees/{$attendee->id}/resend-invite")->assertNotFound();
        $this->postJson('/api/v1/attendees', $this->basicInfo())->assertForbidden();

        $this->event->forceFill(['archived_at' => now()->subMinutes(10)])->save();
        $this->actingAs($this->admin, 'sanctum');
        $this->postJson('/api/v1/attendees', $this->basicInfo())->assertForbidden();
        $this->postJson("/api/v1/attendees/{$attendee->id}/finish")->assertForbidden();
        $this->postJson("/api/v1/attendees/{$attendee->id}/resend-invite")->assertForbidden();
    }
}
