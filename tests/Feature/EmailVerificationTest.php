<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_register_sends_verification_email(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'john@example.com')->first();
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_verify_email_with_valid_hash(): void
    {
        Notification::fake();
        Event::fake();

        $user = User::factory()->unverified()->create();

        $hash = sha1($user->getEmailForVerification());

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->id,
                'hash' => $hash,
            ]
        );

        // Extract the query string from the signed URL
        $urlParts = parse_url($verificationUrl);
        $query = $urlParts['query'] ?? '';

        $response = $this->getJson("/api/v1/auth/email/verify/{$user->id}/{$hash}?" . $query);

        $response->assertStatus(200);

        $this->assertNotNull($user->fresh()->email_verified_at);
        Event::assertDispatched(Verified::class);
    }

    public function test_verify_email_requires_valid_signature(): void
    {
        $user = User::factory()->unverified()->create();
        $hash = sha1($user->getEmailForVerification());

        // Without a signature, the signed middleware should reject the request
        $response = $this->getJson("/api/v1/auth/email/verify/{$user->id}/{$hash}");

        $response->assertStatus(403);

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_verify_email_already_verified(): void
    {
        Event::fake();

        $user = User::factory()->create();

        $hash = sha1($user->getEmailForVerification());

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->id,
                'hash' => $hash,
            ]
        );

        // Extract the query string from the signed URL
        $urlParts = parse_url($verificationUrl);
        $query = $urlParts['query'] ?? '';

        $response = $this->getJson("/api/v1/auth/email/verify/{$user->id}/{$hash}?{$query}");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Email already verified.']);
    }

    public function test_resend_verification_sends_notification(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/email/verification-notification');

        $response->assertStatus(200);

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_resend_verification_when_already_verified(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/email/verification-notification');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Email already verified.']);
    }

    public function test_user_resource_exposes_email_verified(): void
    {
        $verifiedUser = User::factory()->create();
        $unverifiedUser = User::factory()->unverified()->create();

        $response = $this->actingAs($verifiedUser, 'sanctum')
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJsonPath('user.email_verified', true);

        // Verify the email_verified_at timestamp exists and is a string
        $this->assertIsString($response->json('user.email_verified_at'));

        $response = $this->actingAs($unverifiedUser, 'sanctum')
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJsonPath('user.email_verified', false)
            ->assertJsonPath('user.email_verified_at', null);
    }
}
