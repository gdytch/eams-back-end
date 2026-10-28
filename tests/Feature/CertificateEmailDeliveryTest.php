<?php

namespace Tests\Feature;

use App\Mail\CertificateIssuedMail;
use App\Mail\UserAccountInviteMail;
use App\Models\Attendee;
use App\Models\Breakout;
use App\Models\Certificate;
use App\Models\Event;
use App\Models\IssuedCertificate;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use App\Services\DailyMailBudget;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CertificateEmailDeliveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $org;

    private Event $event;

    private Certificate $certificate;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
        Carbon::setTestNow('2026-06-10 12:00:00');
        config(['certificates.email_daily_cap' => 400, 'certificates.email_per_run' => 100]);

        $this->org = Organization::factory()->create();
        $this->event = Event::factory()->for($this->org)->create();
        $this->certificate = $this->publishedCertificate();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function publishedCertificate(array $attributes = [], ?Event $event = null): Certificate
    {
        return Certificate::factory()->for($event ?? $this->event)->create([
            'status' => 'published', 'published_at' => '2026-06-01 00:00:00', 'release_at' => '2026-06-10 09:00:00',
            'release_rule' => 'everyone', 'email_enabled' => true, 'generation_run' => 1, ...$attributes,
        ]);
    }

    private function issue(?Certificate $certificate = null, array $attendee = [], array $issued = []): IssuedCertificate
    {
        $certificate ??= $this->certificate;
        $attendee = Attendee::factory()->for($certificate->event)->create($attendee);
        $path = "certificates/{$certificate->id}/{$attendee->id}.pdf";
        Storage::disk('local')->put($path, '%PDF-fake');

        return IssuedCertificate::create([
            'certificate_id' => $certificate->id, 'attendee_id' => $attendee->id,
            'status' => 'generated', 'file_path' => $path, 'generated_at' => now(), ...$issued,
        ]);
    }

    private function attachedData(CertificateIssuedMail $mail): ?string
    {
        $data = null;
        $mail->attachments()[0]->attachWith(fn () => null, function ($resolve) use (&$data) {
            $data = $resolve();
        });

        return $data;
    }

    private function sendEmails(): void
    {
        $this->artisan('app:send-certificate-emails')->assertSuccessful();
    }

    public function test_emails_the_pdf_as_an_attachment_to_an_eligible_released_attendee(): void
    {
        $row = $this->issue();

        $this->sendEmails();

        Mail::assertSent(CertificateIssuedMail::class, fn (CertificateIssuedMail $mail) => $mail->hasTo($row->attendee->email_address)
            && $mail->attachments()[0]->as === $mail->attachmentName
            && $mail->attachments()[0]->mime === 'application/pdf'
            && $this->attachedData($mail) === '%PDF-fake');
        $this->assertNotNull($row->refresh()->emailed_at);
    }

    public function test_nothing_is_sent_before_release_or_when_the_option_is_off(): void
    {
        $this->issue();
        $this->certificate->update(['release_at' => '2026-06-10 13:00:00']);
        $off = $this->publishedCertificate(['email_enabled' => false]);
        $this->issue($off);

        $this->sendEmails();

        Mail::assertNothingSent();
    }

    public function test_nothing_is_sent_to_non_eligible_attendees_or_those_without_an_email(): void
    {
        $this->certificate->update(['release_rule' => 'checked_in']);
        $this->issue();
        $everyone = $this->publishedCertificate();
        $noEmail = $this->issue($everyone, ['email_address' => null]);

        $this->sendEmails();

        Mail::assertNothingSent();
        $this->assertNull($noEmail->refresh()->emailed_at);
    }

    public function test_only_generated_rows_with_a_stored_file_are_sent(): void
    {
        $pending = $this->issue(null, [], ['status' => 'pending', 'file_path' => null, 'generated_at' => null]);
        $deleted = $this->issue(null, [], ['file_deleted_at' => now()]);

        $this->sendEmails();

        Mail::assertNothingSent();
        $this->assertNull($pending->refresh()->emailed_at);
        $this->assertNull($deleted->refresh()->emailed_at);
    }

    public function test_draft_certificates_and_archived_events_are_skipped(): void
    {
        $draft = $this->publishedCertificate(['status' => 'draft', 'published_at' => null]);
        $this->issue($draft);
        $archived = Event::factory()->for($this->org)->create(['archived_at' => now()->subDay()]);
        $this->issue($this->publishedCertificate([], $archived));

        $this->sendEmails();

        Mail::assertNothingSent();
    }

    public function test_attendees_who_left_the_audience_are_not_emailed(): void
    {
        $union = Union::factory()->create(['organization_id' => $this->org->id]);
        $this->certificate->update(['audience_union_ids' => [$union->id]]);
        $this->issue();

        $this->sendEmails();

        Mail::assertNothingSent();
    }

    public function test_non_pickers_of_the_audience_breakout_are_not_emailed(): void
    {
        $breakout = Breakout::factory()->for($this->event)->create();
        $this->certificate->update(['audience_breakout_ids' => [$breakout->id]]);
        $picked = $this->issue();
        $picked->attendee->breakouts()->attach($breakout->id);
        $this->issue();

        $this->sendEmails();

        Mail::assertSent(CertificateIssuedMail::class, 1);
    }

    public function test_a_run_never_exceeds_the_daily_cap_and_the_rest_goes_out_later(): void
    {
        config(['certificates.email_daily_cap' => 3]);
        foreach (range(1, 5) as $_) {
            $this->issue();
        }

        $this->sendEmails();
        Mail::assertSent(CertificateIssuedMail::class, 3);

        $this->sendEmails();
        Mail::assertSent(CertificateIssuedMail::class, 3);

        Carbon::setTestNow('2026-06-11 00:05:00');
        $this->sendEmails();
        Mail::assertSent(CertificateIssuedMail::class, 5);
    }

    public function test_a_run_sends_no_more_than_the_per_run_limit(): void
    {
        config(['certificates.email_per_run' => 2]);
        foreach (range(1, 5) as $_) {
            $this->issue();
        }

        $this->sendEmails();
        Mail::assertSent(CertificateIssuedMail::class, 2);

        $this->sendEmails();
        Mail::assertSent(CertificateIssuedMail::class, 4);
    }

    public function test_other_app_mail_counts_toward_the_same_daily_cap(): void
    {
        config(['certificates.email_daily_cap' => 3]);
        foreach (range(1, 3) as $_) {
            $this->issue();
        }
        app(DailyMailBudget::class)->record(2);

        $this->sendEmails();

        Mail::assertSent(CertificateIssuedMail::class, 1);
    }

    public function test_sending_other_app_mail_is_counted_by_the_shared_counter(): void
    {
        Mail::swap(new MailManager($this->app));
        $user = User::factory()->create();

        $before = app(DailyMailBudget::class)->used();

        Mail::to($user->email)->send(new UserAccountInviteMail($user));

        $this->assertSame($before + 1, app(DailyMailBudget::class)->used());
    }

    public function test_a_certificate_email_is_never_sent_twice(): void
    {
        $this->issue();

        $this->sendEmails();
        $this->sendEmails();
        Carbon::setTestNow('2026-06-11 12:00:00');
        $this->sendEmails();

        Mail::assertSent(CertificateIssuedMail::class, 1);
    }

    public function test_newly_eligible_attendees_are_picked_up_on_the_next_run(): void
    {
        $this->certificate->update(['release_rule' => 'checked_in']);
        $row = $this->issue();
        $this->sendEmails();
        Mail::assertNothingSent();

        $this->certificate->update(['release_rule' => 'everyone']);
        $this->sendEmails();

        Mail::assertSent(CertificateIssuedMail::class, 1);
        $this->assertNotNull($row->refresh()->emailed_at);
    }

    public function test_a_failed_send_does_not_mark_emailed_or_stop_the_run(): void
    {
        $bad = $this->issue();
        $good = $this->issue();
        Storage::disk('local')->delete($bad->file_path);

        $this->sendEmails();

        $this->assertNull($bad->refresh()->emailed_at);
        $this->assertNotNull($good->refresh()->emailed_at);
        $this->assertSame(1, app(DailyMailBudget::class)->used());
    }

    public function test_email_option_saves_while_published_and_status_reports_emailed_over_total(): void
    {
        $this->actingAs(User::factory()->orgAdmin()->for($this->org)->create(), 'sanctum');
        $this->issue();
        $this->issue(null, ['email_address' => null]);
        $this->sendEmails();

        $base = "/api/v1/events/{$this->event->id}/certificates/{$this->certificate->id}";
        $this->putJson($base, ['email_enabled' => false])->assertOk()->assertJsonPath('data.email_enabled', false);
        $this->putJson($base, ['email_enabled' => true])->assertOk()->assertJsonPath('data.email_enabled', true);

        $this->getJson("$base/status")->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.emailed', 1);
    }
}
