<?php

namespace Tests\Feature;

use App\Jobs\GenerateAttendeeIdCardJob;
use App\Mail\AttendeeAccountInviteMail;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AttendeeInvitationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_creates_attendee_with_email_sends_no_invitation_until_resend(): void
    {
        Queue::fake();

        Mail::fake();

        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/attendees', [
            'event_id' => Event::factory()->create(['organization_id' => $admin->organization_id])->id,
            'organization_level' => 'union',
            'union_id' => Union::factory()->for($org)->create()->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email_address' => 'john@example.com',
        ]);

        $response->assertCreated();

        Mail::assertNothingQueued();

        $attendee = Attendee::where('email_address', 'john@example.com')->first();
        $this->assertNull($attendee->invite_token);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/attendees/{$attendee->id}/resend-invite")->assertOk();

        Mail::assertQueued(AttendeeAccountInviteMail::class);
        $attendee->refresh();
        $this->assertNotNull($attendee->invite_token);
        $this->assertNotNull($attendee->invited_at);
    }

    public function test_admin_creates_attendee_without_email_no_invitation_sent(): void
    {
        Queue::fake();

        Mail::fake();

        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/attendees', [
            'event_id' => Event::factory()->create(['organization_id' => $admin->organization_id])->id,
            'organization_level' => 'union',
            'union_id' => Union::factory()->for($org)->create()->id,
            'first_name' => 'Jane',
            'last_name' => 'Smith',
        ]);

        $response->assertCreated();

        Mail::assertNothingQueued();

        $attendee = Attendee::where('first_name', 'Jane')->first();
        $this->assertNull($attendee->invite_token);
        $this->assertNull($attendee->invited_at);
    }

    public function test_attendee_cannot_be_invited_if_email_already_has_user(): void
    {
        Queue::fake();

        Mail::fake();

        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        User::factory()->for($org)->create(['email' => 'existing@example.com']);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/attendees', [
            'event_id' => Event::factory()->create(['organization_id' => $admin->organization_id])->id,
            'organization_level' => 'union',
            'union_id' => Union::factory()->for($org)->create()->id,
            'first_name' => 'Bob',
            'last_name' => 'Builder',
            'email_address' => 'existing@example.com',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('errors.email_address.0', 'This email address is already in use by another attendee or account.');

        Mail::assertNothingQueued();

        $attendee = Attendee::where('email_address', 'existing@example.com')->first();
        $this->assertNull($attendee);
    }

    public function test_event_registration_inline_attendee_with_email_stays_a_draft_and_sends_no_mail(): void
    {
        Mail::fake();
        Queue::fake([GenerateAttendeeIdCardJob::class]);

        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson("/api/v1/events/{$event->id}/registrations", [
            'first_name' => 'Alice',
            'last_name' => 'Wonder',
            'email_address' => 'alice@example.com',
        ]);

        $response->assertCreated();
        Queue::assertPushed(GenerateAttendeeIdCardJob::class);

        // No mobile number or territory yet: mail waits for the first full registration.
        Mail::assertNothingQueued();
        $this->assertNull(Attendee::where('email_address', 'alice@example.com')->first()->invite_token);
    }

    public function test_register_with_valid_invite_token_links_to_attendee(): void
    {
        $org = Organization::factory()->create();
        $attendee = Attendee::factory()->for(Event::factory()->for($org)->create())->create([
            'email_address' => 'invite@example.com',
            'invite_token' => Attendee::generateUniqueInviteToken(),
            'invited_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'invite@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'invite_token' => $attendee->invite_token,
        ]);

        $response->assertCreated();

        $user = User::where('email', 'invite@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->organization_id);
        $this->assertNotNull($user->email_verified_at);

        $attendee->refresh();
        $this->assertEquals($user->id, $attendee->user_id);
        $this->assertNull($attendee->invite_token);
    }

    public function test_register_with_invite_token_different_email_does_not_verify(): void
    {
        $org = Organization::factory()->create();
        $attendee = Attendee::factory()->for($org)->create([
            'email_address' => 'invite@example.com',
            'invite_token' => Attendee::generateUniqueInviteToken(),
            'invited_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'organization_id' => $org->id,
            'organization_level' => 'union',
            'union_id' => Union::factory()->for($org)->create()->id,
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'different@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'invite_token' => $attendee->invite_token,
        ]);

        $response->assertCreated();

        $user = User::where('email', 'different@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);
    }

    public function test_register_with_already_claimed_invite_token_fails(): void
    {
        $org = Organization::factory()->create();
        $user1 = User::factory()->for($org)->create();
        $attendee = Attendee::factory()->for($org)->for($user1)->create([
            'email_address' => 'claimed@example.com',
            'invite_token' => null,
            'invited_at' => now()->subDay(),
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'organization_id' => $org->id,
            'organization_level' => 'union',
            'union_id' => Union::factory()->for($org)->create()->id,
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'newuser@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'invite_token' => 'invalid_token',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('invite_token');
    }

    public function test_public_event_self_registration_does_not_send_invite(): void
    {
        Mail::fake();
        Queue::fake([GenerateAttendeeIdCardJob::class]);

        $org = Organization::factory()->create();
        $user = User::factory()->attendee()->create(['organization_id' => $org->id]);
        $event = Event::factory()->for($org)->create(['status' => 'published']);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/public/event/{$event->invite_token}/registration/basic-info", [
            'first_name' => 'Jane', 'last_name' => 'Doe', 'mobile_no' => '09171234567',
        ]);

        $response->assertOk();

        Mail::assertNotQueued(AttendeeAccountInviteMail::class);
    }

    public function test_existing_attendee_with_no_email_has_email_added_later_not_invited(): void
    {
        Mail::fake();

        $org = Organization::factory()->create();
        $attendee = Attendee::factory()->for($org)->create(['email_address' => null]);
        $checker = User::factory()->checker()->for($org)->create();

        // Update attendee to add email later
        $this->actingAs($checker, 'sanctum')->patchJson("/api/v1/attendees/{$attendee->id}", [
            'email_address' => 'newemail@example.com',
        ]);

        Mail::assertNothingQueued();

        $attendee->refresh();
        $this->assertNull($attendee->invite_token);
    }
}
