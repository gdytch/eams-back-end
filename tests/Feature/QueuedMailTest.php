<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Mail\AttendeeAccountInviteMail;
use App\Mail\AttendeeRegistrationWelcomeMail;
use App\Mail\UserAccountInviteMail;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use App\Notifications\AttendeeQrClaimEmailCodeNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QueuedMailTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $org;

    private Event $event;

    private Union $union;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();

        $this->org = Organization::factory()->create();
        $this->event = Event::factory()->for($this->org)->state(['status' => EventStatus::Published])->create();
        $this->union = Union::factory()->for($this->org)->create();
    }

    private function publicUrl(string $path): string
    {
        return "/api/v1/public/event/{$this->event->invite_token}/registration{$path}";
    }

    private function territory(): array
    {
        return ['organization_level' => 'union', 'union_id' => $this->union->id];
    }

    public function test_first_full_self_registration_queues_one_welcome_and_recompletion_sends_nothing(): void
    {
        $user = User::factory()->attendee()->for($this->org)->create();
        $this->actingAs($user, 'sanctum');
        $this->putJson($this->publicUrl('/basic-info'), ['first_name' => 'Jane', 'last_name' => 'Doe', 'mobile_no' => '09171234567'])->assertOk();
        $this->putJson($this->publicUrl('/territory'), $this->territory())->assertOk();
        Mail::assertNothingQueued();

        $this->postJson($this->publicUrl('/finish'))->assertOk();

        Mail::assertQueued(AttendeeRegistrationWelcomeMail::class, 1);
        Mail::assertQueued(AttendeeRegistrationWelcomeMail::class, fn ($mail) => $mail->hasTo($user->email) && $mail->queue === 'mail');
        Mail::assertNotQueued(AttendeeAccountInviteMail::class);

        // Becomes incomplete, then complete again: first_registered_at already set, no second mail.
        $attendee = Attendee::where('user_id', $user->id)->sole();
        $attendee->update(['organization_level' => null, 'union_id' => null]);
        $this->assertNull($attendee->refresh()->registration_completed_at);
        $this->putJson($this->publicUrl('/territory'), $this->territory())->assertOk();
        $this->postJson($this->publicUrl('/finish'))->assertOk();

        Mail::assertQueued(AttendeeRegistrationWelcomeMail::class, 1);
    }

    public function test_welcome_mail_carries_event_details_and_dashboard_link_without_invite(): void
    {
        $attendee = Attendee::factory()->for($this->event)->for(User::factory()->attendee()->for($this->org), 'user')->create();

        $html = (new AttendeeRegistrationWelcomeMail($attendee))->render();

        $this->assertStringContainsString($this->event->name, $html);
        $this->assertStringContainsString(config('app.frontend_url'), $html);
        $this->assertStringNotContainsString('invite=', $html);
    }

    public function test_admin_registered_attendee_with_email_gets_one_merged_mail_at_finish_only(): void
    {
        $admin = User::factory()->orgAdmin()->for($this->org)->create();
        $this->actingAs($admin, 'sanctum');
        $id = $this->postJson('/api/v1/attendees', [
            'event_id' => $this->event->id, 'first_name' => 'Ana', 'last_name' => 'Reyes', 'mobile_no' => '09170000000', 'email_address' => 'ana@example.com',
        ])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/attendees/{$id}", $this->territory())->assertOk();

        Mail::assertNothingQueued();

        $this->postJson("/api/v1/attendees/{$id}/finish")->assertOk();

        Mail::assertQueued(AttendeeRegistrationWelcomeMail::class, 1);
        Mail::assertNotQueued(AttendeeAccountInviteMail::class);
        $attendee = Attendee::findOrFail($id);
        $this->assertNotNull($attendee->invite_token);
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendee.account_invited', 'auditable_id' => $id]);
        $html = (new AttendeeRegistrationWelcomeMail($attendee))->render();
        $this->assertStringContainsString('invite='.$attendee->invite_token, $html);
    }

    public function test_admin_registered_attendee_without_email_gets_no_mail(): void
    {
        $attendee = Attendee::factory()->for($this->event)->draft()->create(['email_address' => null, 'user_id' => null, 'organization_level' => 'union', 'union_id' => $this->union->id]);

        $attendee->update(['registration_confirmed_at' => now()]);

        $this->assertNotNull($attendee->refresh()->first_registered_at);
        Mail::assertNothingQueued();
    }

    public function test_email_of_an_existing_user_gets_the_plain_welcome_mail(): void
    {
        $user = User::factory()->attendee()->for($this->org)->create();
        $attendee = Attendee::factory()->for($this->event)->draft()->create(['email_address' => $user->email, 'user_id' => null, 'organization_level' => 'union', 'union_id' => $this->union->id]);

        $attendee->update(['registration_confirmed_at' => now()]);

        Mail::assertQueued(AttendeeRegistrationWelcomeMail::class, fn ($mail) => $mail->hasTo($user->email));
        $this->assertNull($attendee->refresh()->invite_token);
    }

    public function test_mail_goes_to_the_mail_queue_and_auth_mail_to_the_high_queue(): void
    {
        $user = User::factory()->create();
        $attendee = Attendee::factory()->for($this->event)->create();

        $this->assertSame('mail', (new AttendeeAccountInviteMail($attendee))->queue);
        $this->assertSame('mail', (new UserAccountInviteMail($user))->queue);

        foreach ([new VerifyEmailNotification, new ResetPasswordNotification('t'), new AttendeeQrClaimEmailCodeNotification('123456')] as $notification) {
            $this->assertSame(['mail' => 'high'], $notification->viaQueues());
        }
    }

    public function test_over_the_rate_limit_the_job_is_released_not_failed(): void
    {
        config(['mail.rate_limit_per_minute' => 2]);
        $attendee = Attendee::factory()->for($this->event)->create();
        $job = new SendQueuedMailable(new AttendeeAccountInviteMail($attendee));
        $queueJob = \Mockery::mock(Job::class);
        $queueJob->shouldReceive('release')->once();
        $queueJob->shouldReceive('fail')->never();
        $job->setJob($queueJob);

        $ran = 0;
        foreach (range(1, 3) as $ignored) {
            (new RateLimited('mail'))->handle($job, function () use (&$ran) {
                $ran++;
            });
        }

        $this->assertSame(2, $ran);
        $this->assertInstanceOf(RateLimited::class, (new AttendeeAccountInviteMail($attendee))->middleware()[0]);
        $this->assertNotNull($job->retryUntil());
    }
}
