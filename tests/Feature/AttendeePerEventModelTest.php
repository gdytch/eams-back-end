<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use App\Services\AttendeeRegistrationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttendeePerEventModelTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_organization_follows_the_event_and_ignores_the_given_organization(): void
    {
        $event = Event::factory()->create();
        $other = Organization::factory()->create();

        $attendee = Attendee::create([
            'organization_id' => $other->id,
            'event_id' => $event->id,
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
        ]);

        $this->assertSame($event->organization_id, $attendee->organization_id);

        $attendee->update(['organization_id' => $other->id]);
        $this->assertSame($event->organization_id, $attendee->fresh()->organization_id);
    }

    public function test_an_attendee_in_an_event_gets_a_unique_qr_token_and_registered_at(): void
    {
        $event = Event::factory()->create();

        $first = Attendee::factory()->for($event)->create();
        $second = Attendee::factory()->for($event)->create();

        $this->assertNotEmpty($first->qr_token);
        $this->assertNotSame($first->qr_token, $second->qr_token);
        $this->assertNotNull($first->registered_at);
        $this->assertStringContainsString("/attendee/{$event->id}", $first->qr_token);
    }

    public function test_the_same_user_cannot_have_two_attendees_in_one_event_but_can_in_two_events(): void
    {
        $user = User::factory()->attendee()->create();
        $event = Event::factory()->create();
        Attendee::factory()->for($event)->for($user)->create();

        Attendee::factory()->for(Event::factory()->create())->for($user)->create();
        $this->assertSame(2, Attendee::withoutGlobalScopes()->where('user_id', $user->id)->count());

        $this->expectException(UniqueConstraintViolationException::class);
        Attendee::factory()->for($event)->for($user)->create();
    }

    public function test_event_lists_and_shows_its_attendees_as_registrations(): void
    {
        Queue::fake();
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $other = Event::factory()->for($org)->create();
        $mine = Attendee::factory()->for($event)->create(['last_name' => 'Zulu']);
        Attendee::factory()->for($event)->create(['last_name' => 'Alpha']);
        $foreign = Attendee::factory()->for($other)->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/events/{$event->id}/registrations?sort_by=last_name&sort_order=asc")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.attendee.last_name', 'Alpha');

        $this->getJson("/api/v1/events/{$event->id}/registrations/{$mine->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $mine->id)
            ->assertJsonPath('data.attendee_id', $mine->id)
            ->assertJsonPath('data.qr_token', $mine->qr_token);

        // An attendee of another event is not reachable through this event.
        $this->getJson("/api/v1/events/{$event->id}/registrations/{$foreign->id}")->assertNotFound();
        $this->deleteJson("/api/v1/events/{$event->id}/registrations/{$foreign->id}")->assertNotFound();

        $this->deleteJson("/api/v1/events/{$event->id}/registrations/{$mine->id}")->assertNoContent();
        $this->assertDatabaseMissing('attendees', ['id' => $mine->id]);
    }

    public function test_checker_without_access_to_the_event_cannot_view_its_attendee(): void
    {
        $org = Organization::factory()->create();
        $allowed = Event::factory()->for($org)->create();
        $restricted = Event::factory()->for($org)->create();
        $checker = User::factory()->checker()->for($org)->create();
        $checker->accessibleEvents()->attach($allowed);
        $attendee = Attendee::factory()->for($restricted)->create();

        $this->actingAs($checker, 'sanctum');
        $this->assertFalse($checker->can('view', $attendee));
        $this->assertTrue($checker->can('view', Attendee::factory()->for($allowed)->create()));
    }

    public function test_registering_a_user_prefills_from_their_latest_attendee(): void
    {
        Queue::fake();
        $org = Organization::factory()->create();
        $user = User::factory()->attendee()->create();
        $union = Union::factory()->for($org)->create();
        Attendee::factory()->for(Event::factory()->for($org)->create())->for($user)->create(['first_name' => 'Old']);
        Attendee::factory()->for(Event::factory()->for($org)->create())->for($user)->create([
            'first_name' => 'Latest', 'last_name' => 'Name', 'mobile_no' => '0917', 'organization_level' => 'union', 'union_id' => $union->id,
        ]);
        $event = Event::factory()->for($org)->create();

        [$attendee, $created] = app(AttendeeRegistrationService::class)->registerUser($user, $event);

        $this->assertTrue($created);
        $this->assertSame(['Latest', 'Name', '0917', $union->id, $event->id, $user->id], [
            $attendee->first_name, $attendee->last_name, $attendee->mobile_no, $attendee->union_id, $attendee->event_id, $attendee->user_id,
        ]);
        [$again, $createdAgain] = app(AttendeeRegistrationService::class)->registerUser($user, $event);
        $this->assertFalse($createdAgain);
        $this->assertTrue($again->is($attendee));
    }

    public function test_registering_a_user_without_attendees_falls_back_to_the_user_fields(): void
    {
        $user = User::factory()->attendee()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
        $event = Event::factory()->create();

        [$attendee] = app(AttendeeRegistrationService::class)->registerUser($user, $event);

        $this->assertSame(['Jane', 'Doe', $user->email], [$attendee->first_name, $attendee->last_name, $attendee->email_address]);
    }

    public function test_prefill_drops_territory_from_another_organization_and_copies_photos(): void
    {
        $otherOrg = Organization::factory()->create();
        $union = Union::factory()->for($otherOrg)->create();
        $user = User::factory()->attendee()->create();
        $source = Attendee::factory()->for(Event::factory()->for($otherOrg)->create())->for($user)->create([
            'organization_level' => 'union', 'union_id' => $union->id,
        ]);
        Storage::disk('local')->put("attendees/{$source->id}/photo-a.webp", 'img');
        $source->update(['photo_paths' => ['sm' => "attendees/{$source->id}/photo-a.webp"]]);
        $event = Event::factory()->create();

        $copy = app(AttendeeRegistrationService::class)->registerFrom($source->fresh(), $event, null);

        $this->assertNull($copy->union_id);
        $this->assertNull($copy->organization_level);
        $this->assertSame("attendees/{$copy->id}/photo-a.webp", $copy->photo_paths['sm']);
        Storage::disk('local')->assertExists("attendees/{$copy->id}/photo-a.webp");
        Storage::disk('local')->assertExists("attendees/{$source->id}/photo-a.webp");
    }
}
