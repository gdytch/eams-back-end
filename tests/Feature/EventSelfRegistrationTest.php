<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Jobs\GenerateAttendeeIdCardJob;
use App\Models\Attendee;
use App\Models\Church;
use App\Models\Event;
use App\Models\Mission;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EventSelfRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $org;

    private Event $event;

    private User $user;

    private Union $union;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->org = Organization::factory()->create();
        $this->event = Event::factory()->for($this->org)->state(['status' => EventStatus::Published])->create();
        $this->user = User::factory()->attendee()->for($this->org)->create();
        $this->union = Union::factory()->create(['organization_id' => $this->org->id]);
    }

    private function url(string $path = '', ?Event $event = null): string
    {
        return '/api/v1/public/event/'.($event ?? $this->event)->invite_token.'/registration'.$path;
    }

    /** @return array<string, string> */
    private function basicInfo(array $overrides = []): array
    {
        return [...['first_name' => 'Jane', 'last_name' => 'Doe', 'mobile_no' => '09171234567'], ...$overrides];
    }

    /** @return array<string, mixed> */
    private function unionTerritory(): array
    {
        return ['organization_level' => 'union', 'union_id' => $this->union->id];
    }

    private function completeAll(?User $user = null): void
    {
        $this->actingAs($user ?? $this->user, 'sanctum');
        $this->putJson($this->url('/basic-info'), $this->basicInfo())->assertOk();
        $this->putJson($this->url('/territory'), $this->unionTerritory())->assertOk();
        $this->postJson($this->url('/finish'))->assertOk();
    }

    public function test_wizard_requires_authentication(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
        $this->putJson($this->url('/basic-info'))->assertUnauthorized();
        $this->postJson($this->url('/finish'))->assertUnauthorized();
    }

    public function test_unpublished_event_is_not_found(): void
    {
        $draft = Event::factory()->for($this->org)->state(['status' => EventStatus::Draft])->create();

        $this->actingAs($this->user, 'sanctum')
            ->putJson($this->url('/basic-info', $draft), $this->basicInfo())->assertNotFound();
        $this->putJson($this->url('/territory', $draft), $this->unionTerritory())->assertNotFound();
        $this->getJson($this->url('', $draft))->assertNotFound();
    }

    public function test_read_before_any_save_returns_no_attendee_and_all_steps_missing(): void
    {
        $this->actingAs($this->user, 'sanctum')->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('data', null)
            ->assertJsonPath('steps.review', ['confirmation']);
        $this->assertDatabaseCount('attendees', 0);
    }

    public function test_basic_info_save_creates_a_draft_without_qr_token(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->putJson($this->url('/basic-info'), $this->basicInfo())
            ->assertOk()
            ->assertJsonPath('data.attendee.first_name', 'Jane')
            ->assertJsonPath('steps.basic_info', []);

        $attendee = Attendee::where('user_id', $this->user->id)->sole();
        $this->assertSame($this->event->id, $attendee->event_id);
        $this->assertSame($this->user->email, $attendee->email_address);
        $this->assertNull($attendee->qr_token);
        $this->assertNull($attendee->registration_completed_at);
        $this->assertNull($attendee->first_registered_at);
    }

    public function test_basic_info_does_not_accept_a_client_email(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->putJson($this->url('/basic-info'), $this->basicInfo(['email_address' => 'other@example.com']))->assertOk();

        $this->assertSame($this->user->email, Attendee::where('user_id', $this->user->id)->sole()->email_address);
    }

    public function test_basic_info_validates_required_fields(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->putJson($this->url('/basic-info'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'last_name', 'mobile_no']);
    }

    public function test_second_save_from_another_session_updates_the_same_row(): void
    {
        $this->actingAs($this->user, 'sanctum')->putJson($this->url('/basic-info'), $this->basicInfo())->assertOk();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->user, 'sanctum')
            ->putJson($this->url('/basic-info'), $this->basicInfo(['first_name' => 'Janet']))->assertOk();

        $this->assertSame('Janet', Attendee::where('user_id', $this->user->id)->sole()->first_name);
    }

    public function test_territory_before_basic_info_is_rejected(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->putJson($this->url('/territory'), $this->unionTerritory())->assertUnprocessable();
        $this->assertDatabaseCount('attendees', 0);
    }

    public function test_territory_saves_union_level_and_drops_mission_and_church(): void
    {
        $this->actingAs($this->user, 'sanctum')->putJson($this->url('/basic-info'), $this->basicInfo())->assertOk();

        $this->putJson($this->url('/territory'), $this->unionTerritory() + ['mission_id' => null])
            ->assertOk()->assertJsonPath('steps.territory', []);

        $attendee = Attendee::where('user_id', $this->user->id)->sole();
        $this->assertSame($this->union->id, $attendee->union_id);
        $this->assertNull($attendee->church_id);
    }

    public function test_mission_level_territory_needs_a_church_inside_the_mission(): void
    {
        $mission = Mission::factory()->create(['organization_id' => $this->org->id, 'union_id' => $this->union->id]);
        $church = Church::factory()->create(['organization_id' => $this->org->id, 'mission_id' => $mission->id]);
        $stray = Church::factory()->create(['organization_id' => $this->org->id]);
        $this->actingAs($this->user, 'sanctum')->putJson($this->url('/basic-info'), $this->basicInfo())->assertOk();
        $body = ['organization_level' => 'mission', 'union_id' => $this->union->id, 'mission_id' => $mission->id];

        $this->putJson($this->url('/territory'), $body)->assertUnprocessable()->assertJsonValidationErrors(['church_id']);
        $this->putJson($this->url('/territory'), $body + ['church_id' => $stray->id])->assertUnprocessable()->assertJsonValidationErrors(['church_id']);
        $this->putJson($this->url('/territory'), $body + ['church_id' => $church->id])->assertOk();
    }

    public function test_territory_of_another_organization_is_rejected(): void
    {
        $foreign = Union::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->actingAs($this->user, 'sanctum')->putJson($this->url('/basic-info'), $this->basicInfo())->assertOk();

        $this->putJson($this->url('/territory'), ['organization_level' => 'union', 'union_id' => $foreign->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['union_id']);
    }

    public function test_finish_on_a_complete_attendee_registers_and_queues_the_id_card(): void
    {
        $this->completeAll();

        $attendee = Attendee::where('user_id', $this->user->id)->sole();
        $this->assertNotNull($attendee->registration_completed_at);
        $this->assertNotNull($attendee->first_registered_at);
        $this->assertNotNull($attendee->qr_token);
        Queue::assertPushed(GenerateAttendeeIdCardJob::class, 1);
    }

    public function test_finish_on_an_incomplete_attendee_names_the_missing_steps(): void
    {
        $this->actingAs($this->user, 'sanctum')->putJson($this->url('/basic-info'), $this->basicInfo())->assertOk();

        $this->postJson($this->url('/finish'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.steps', ['territory']);

        $attendee = Attendee::where('user_id', $this->user->id)->sole();
        $this->assertNull($attendee->registration_confirmed_at);
        $this->assertNull($attendee->qr_token);
        Queue::assertNothingPushed();
    }

    public function test_finish_without_an_attendee_is_not_found(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson($this->url('/finish'))->assertNotFound();
    }

    public function test_finishing_twice_does_not_queue_a_second_id_card(): void
    {
        $this->completeAll();
        $this->postJson($this->url('/finish'))->assertOk();

        Queue::assertPushed(GenerateAttendeeIdCardJob::class, 1);
    }

    public function test_changing_the_name_after_registration_regenerates_the_id_card(): void
    {
        $this->completeAll();

        $this->putJson($this->url('/basic-info'), $this->basicInfo(['first_name' => 'Janet']))->assertOk();

        Queue::assertPushed(GenerateAttendeeIdCardJob::class, 2);
        $this->assertNotNull(Attendee::where('user_id', $this->user->id)->sole()->registration_completed_at);
    }

    public function test_changing_the_territory_after_registration_regenerates_the_id_card(): void
    {
        $this->completeAll();
        $other = Union::factory()->create(['organization_id' => $this->org->id]);

        $this->putJson($this->url('/territory'), ['organization_level' => 'union', 'union_id' => $other->id])->assertOk();

        Queue::assertPushed(GenerateAttendeeIdCardJob::class, 2);
    }

    public function test_changing_the_name_of_a_once_registered_but_now_incomplete_attendee_regenerates_the_id_card(): void
    {
        $this->completeAll();
        Attendee::where('user_id', $this->user->id)->sole()->update(['mobile_no' => null]);

        $this->putJson($this->url('/basic-info'), $this->basicInfo(['first_name' => 'Janet']))->assertOk();

        Queue::assertPushed(GenerateAttendeeIdCardJob::class, 2);
    }

    public function test_saving_unchanged_card_data_does_not_regenerate_the_id_card(): void
    {
        $this->completeAll();

        $this->putJson($this->url('/basic-info'), $this->basicInfo(['mobile_no' => '09999999999']))->assertOk();

        Queue::assertPushed(GenerateAttendeeIdCardJob::class, 1);
    }

    public function test_unverified_email_matching_an_unlinked_attendee_returns_the_verify_email_state(): void
    {
        $existing = Attendee::factory()->for($this->event)->create(['email_address' => 'taken@example.com']);
        $user = User::factory()->attendee()->unverified()->create(['email' => 'taken@example.com']);
        $this->actingAs($user, 'sanctum');

        $this->getJson($this->url())->assertStatus(409)->assertJsonPath('status', 'verify_email');
        $this->putJson($this->url('/basic-info'), $this->basicInfo())->assertStatus(409)->assertJsonPath('status', 'verify_email');
        $this->putJson($this->url('/territory'), $this->unionTerritory())->assertStatus(409)->assertJsonPath('status', 'verify_email');
        $this->postJson($this->url('/finish'))->assertStatus(409)->assertJsonPath('status', 'verify_email');

        $this->assertNull($existing->fresh()->user_id);
        $this->assertDatabaseCount('attendees', 1);
    }

    public function test_verified_user_is_linked_to_the_existing_attendee_and_the_wizard_opens_on_it(): void
    {
        $existing = Attendee::factory()->for($this->event)->create(['email_address' => 'taken@example.com']);
        $user = User::factory()->attendee()->create(['email' => 'taken@example.com']);

        $this->actingAs($user, 'sanctum')->getJson($this->url())
            ->assertOk()->assertJsonPath('data.id', $existing->id);

        $this->assertSame($user->id, $existing->fresh()->user_id);
        $this->assertDatabaseCount('attendees', 1);
    }

    public function test_saving_sets_the_user_organization_when_missing(): void
    {
        $user = User::factory()->attendee()->state(['organization_id' => null])->create();

        $this->actingAs($user, 'sanctum')->putJson($this->url('/basic-info'), $this->basicInfo())->assertOk();

        $this->assertSame($this->org->id, $user->fresh()->organization_id);
    }

    public function test_archived_event_blocks_saves_but_not_the_read(): void
    {
        $this->event->forceFill(['archived_at' => now()->subMinute()])->save();
        $this->actingAs($this->user, 'sanctum');

        $this->putJson($this->url('/basic-info'), $this->basicInfo())->assertForbidden();
        $this->putJson($this->url('/territory'), $this->unionTerritory())->assertForbidden();
        $this->postJson($this->url('/finish'))->assertForbidden();
        $this->getJson($this->url())->assertOk();
        $this->assertDatabaseCount('attendees', 0);
    }

    public function test_the_single_step_register_route_is_gone(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/public/event/{$this->event->invite_token}/register")->assertStatus(404);
    }

    public function test_basic_info_validation_names_the_mobile_number_readably(): void
    {
        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->create(['status' => 'published']);
        $user = User::factory()->attendee()->create(['organization_id' => $org->id]);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/public/event/{$event->invite_token}/registration/basic-info", ['first_name' => 'A', 'last_name' => 'B'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.mobile_no.0', 'The mobile number field is required.');
    }
}
