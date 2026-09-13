<?php

namespace Tests\Feature;

use App\Mail\EventRegistrationWelcomeMail;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EventRegistrationWelcomeEmailTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_welcome_email_sent_on_self_registration(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $event = Event::factory()->create(['status' => 'Published']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/public/event/{$event->invite_token}/register");

        $response->assertStatus(201);

        // Verify mail was sent to user's email
        Mail::assertSent(EventRegistrationWelcomeMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    public function test_welcome_email_sent_on_admin_registration(): void
    {
        Mail::fake();

        $user = User::factory()->admin()->create();
        $event = Event::factory()->create();
        $attendeeEmail = 'attendee@example.com';

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/events/{$event->id}/registrations", [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'email_address' => $attendeeEmail,
            ]);

        $response->assertStatus(201);

        // Verify mail was sent to provided email
        Mail::assertSent(EventRegistrationWelcomeMail::class, function ($mail) use ($attendeeEmail) {
            return $mail->hasTo($attendeeEmail);
        });
    }

    public function test_no_email_sent_when_attendee_has_no_email(): void
    {
        Mail::fake();

        $user = User::factory()->admin()->create();
        $event = Event::factory()->create();

        // Register attendee without email_address
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/events/{$event->id}/registrations", [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
            ]);

        $response->assertStatus(201);

        // Verify no mail was sent by checking the sent count is 0
        Mail::assertNothingSent();
    }

    public function test_welcome_email_contains_event_details(): void
    {
        Mail::fake();

        $user = User::factory()->create(['first_name' => 'John', 'last_name' => 'Doe']);
        $event = Event::factory()
            ->has(
                EventSession::factory()->count(2),
                'sessions'
            )
            ->create([
                'status' => 'Published',
                'start_date' => now()->addDays(1),
                'end_date' => now()->addDays(2),
                'venue' => '123 Main St',
            ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/public/event/{$event->invite_token}/register")
            ->assertStatus(201);

        Mail::assertSent(EventRegistrationWelcomeMail::class, function ($mail) use ($event) {
            return $mail->registration->event->id === $event->id;
        });
    }
}
