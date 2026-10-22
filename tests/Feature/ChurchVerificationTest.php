<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\Church;
use App\Models\Event;
use App\Models\Mission;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ChurchVerificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $org;

    private Union $union;

    private Mission $mission;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->org = Organization::factory()->create();
        $this->union = Union::factory()->for($this->org)->create();
        $this->mission = Mission::factory()->for($this->org)->for($this->union)->create();
        $this->admin = User::factory()->orgAdmin()->for($this->org)->create();
    }

    private function church(array $attributes = []): Church
    {
        return Church::factory()->for($this->org)->for($this->union)->for($this->mission)->create($attributes);
    }

    private function createChurch(User $user, array $payload)
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/v1/churches', [
            'union_id' => $this->union->id,
            'mission_id' => $this->mission->id,
            ...$payload,
        ]);
    }

    private function attendeePayload(array $overrides = []): array
    {
        return [
            'event_id' => Event::factory()->create(['organization_id' => $this->org->id])->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            ...$overrides,
        ];
    }

    public function test_admin_created_church_is_verified_attendee_created_is_not(): void
    {
        $this->createChurch($this->admin, ['name' => 'Admin Church', 'address' => '1 A St'])
            ->assertCreated()
            ->assertJsonPath('data.verified_by', $this->admin->id);

        $attendee = User::factory()->for($this->org)->create(['role' => 'attendee']);
        $this->createChurch($attendee, ['name' => 'Own Church', 'address' => '2 B St'])
            ->assertCreated()
            ->assertJsonMissingPath('data.verified_at');

        $this->assertNotNull(Church::where('name', 'Admin Church')->value('verified_at'));
        $this->assertNull(Church::where('name', 'Own Church')->value('verified_at'));
    }

    public function test_address_is_required_on_create_and_edit(): void
    {
        $this->createChurch($this->admin, ['name' => 'No Address'])->assertJsonValidationErrors('address');

        $church = $this->church();
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/churches/{$church->id}", ['address' => ''])
            ->assertJsonValidationErrors('address');
    }

    public function test_mission_must_belong_to_union(): void
    {
        $otherUnion = Union::factory()->for($this->org)->create();

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/churches', [
            'union_id' => $otherUnion->id,
            'mission_id' => $this->mission->id,
            'name' => 'X',
            'address' => 'Y',
        ])->assertJsonValidationErrors('mission_id');
    }

    public function test_same_normalized_name_and_address_is_blocked_with_existing_church(): void
    {
        $existing = $this->church(['name' => 'Grace Church', 'address' => '12 Main St']);

        $this->createChurch($this->admin, ['name' => '  grace   CHURCH ', 'address' => '12  main st'])
            ->assertUnprocessable()
            ->assertJsonPath('existing_church.id', $existing->id);

        $this->assertSame(1, Church::where('mission_id', $this->mission->id)->count());
    }

    public function test_same_name_other_address_needs_confirm_flag(): void
    {
        $existing = $this->church(['name' => 'Grace Church', 'address' => '12 Main St']);

        $this->createChurch($this->admin, ['name' => 'Grace Church', 'address' => '99 Other Rd'])
            ->assertUnprocessable()
            ->assertJsonPath('existing_churches.0.id', $existing->id);

        $this->createChurch($this->admin, ['name' => 'Grace Church', 'address' => '99 Other Rd', 'confirm_same_name' => true])
            ->assertCreated();
    }

    public function test_same_name_in_another_mission_is_allowed(): void
    {
        $this->church(['name' => 'Grace Church', 'address' => '12 Main St']);
        $other = Mission::factory()->for($this->org)->for($this->union)->create();

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/churches', [
            'union_id' => $this->union->id,
            'mission_id' => $other->id,
            'name' => 'Grace Church',
            'address' => '12 Main St',
        ])->assertCreated();
    }

    public function test_similar_name_creates_with_did_you_mean_list(): void
    {
        $similar = $this->church(['name' => 'Grace Church', 'address' => '12 Main St']);

        $this->createChurch($this->admin, ['name' => 'Grace Chruch', 'address' => '5 Elm St'])
            ->assertCreated()
            ->assertJsonPath('similar_churches.0.id', $similar->id);
    }

    public function test_edit_cannot_collide_with_another_church(): void
    {
        $this->church(['name' => 'Grace Church', 'address' => '12 Main St']);
        $other = $this->church(['name' => 'Other', 'address' => '1 St']);

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/churches/{$other->id}", ['name' => 'Grace Church', 'address' => '12 Main St'])
            ->assertUnprocessable()
            ->assertJsonStructure(['existing_church']);
    }

    public function test_unverified_filter_and_flag_are_admin_only(): void
    {
        $this->church(['verified_at' => now()]);
        $unverified = $this->church();

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/churches?unverified=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $unverified->id)
            ->assertJsonPath('data.0.verified_at', null);

        $attendee = User::factory()->for($this->org)->create(['role' => 'attendee']);
        $this->actingAs($attendee, 'sanctum')->getJson('/api/v1/churches?unverified=1')
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.verified_at');
    }

    public function test_verify_needs_an_address(): void
    {
        $noAddress = $this->church(['address' => null]);
        $church = $this->church();

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/churches/{$noAddress->id}/verify")
            ->assertUnprocessable()->assertJsonValidationErrors('address');

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/churches/{$church->id}/verify")->assertOk();
        $this->assertNotNull($church->fresh()->verified_at);
        $this->assertSame($this->admin->id, $church->fresh()->verified_by);
    }

    public function test_attendee_and_other_org_admin_cannot_verify_or_merge(): void
    {
        $church = $this->church();
        $target = $this->church();
        $attendee = User::factory()->for($this->org)->create(['role' => 'attendee']);
        $foreignAdmin = User::factory()->orgAdmin()->for(Organization::factory()->create())->create();

        foreach ([$attendee, $foreignAdmin] as $user) {
            $this->actingAs($user, 'sanctum')->postJson("/api/v1/churches/{$church->id}/verify")->assertStatus(in_array($user->id, [$attendee->id]) ? 403 : 404);
            $this->actingAs($user, 'sanctum')->postJson("/api/v1/churches/{$church->id}/merge", ['target_church_id' => $target->id])->assertStatus(in_array($user->id, [$attendee->id]) ? 403 : 404);
        }
    }

    public function test_merge_repoints_active_nulls_archived_deletes_source_and_audits(): void
    {
        $source = $this->church();
        $target = $this->church();
        $active = Event::factory()->create(['organization_id' => $this->org->id]);
        $archived = Event::factory()->create(['organization_id' => $this->org->id, 'archived_at' => now()]);

        $inActive = Attendee::factory()->create(['organization_id' => $this->org->id, 'event_id' => $active->id, 'church_id' => $source->id]);
        $inArchived = Attendee::factory()->create(['organization_id' => $this->org->id, 'event_id' => $archived->id, 'church_id' => $source->id]);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/churches/{$source->id}/merge", ['target_church_id' => $target->id])
            ->assertOk()->assertJsonPath('data.id', $target->id);

        $this->assertSame($target->id, $inActive->fresh()->church_id);
        $this->assertNull($inArchived->fresh()->church_id);
        $this->assertDatabaseMissing('churches', ['id' => $source->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'church.merged', 'auditable_id' => $target->id]);
        $this->assertSame(1, AuditLog::where('action', 'church.merged')->count());
    }

    public function test_merge_rejects_church_of_another_mission_or_itself(): void
    {
        $source = $this->church();
        $otherMission = Mission::factory()->for($this->org)->for($this->union)->create();
        $elsewhere = Church::factory()->for($this->org)->for($this->union)->for($otherMission)->create();

        foreach ([$elsewhere->id, $source->id] as $targetId) {
            $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/churches/{$source->id}/merge", ['target_church_id' => $targetId])
                ->assertJsonValidationErrors('target_church_id');
        }
        $this->assertDatabaseHas('churches', ['id' => $source->id]);
    }

    public function test_union_level_attendee_stores_mission_and_church_null(): void
    {
        $church = $this->church();

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/attendees', $this->attendeePayload([
            'organization_level' => 'union',
            'union_id' => $this->union->id,
            'mission_id' => $this->mission->id,
            'church_id' => $church->id,
        ]))->assertCreated();

        $this->assertDatabaseHas('attendees', ['first_name' => 'Jane', 'mission_id' => null, 'church_id' => null]);
    }

    public function test_mission_level_attendee_needs_mission_and_church(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/attendees', $this->attendeePayload([
            'organization_level' => 'mission',
            'union_id' => $this->union->id,
            'mission_id' => $this->mission->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('church_id');
    }

    public function test_attendee_territory_hierarchy_and_organization_are_enforced(): void
    {
        $otherMission = Mission::factory()->for($this->org)->for($this->union)->create();
        $otherMissionChurch = Church::factory()->for($this->org)->for($this->union)->for($otherMission)->create();
        $foreign = Church::factory()->create();

        $payload = fn (Church $church) => $this->attendeePayload([
            'organization_level' => 'mission',
            'union_id' => $this->union->id,
            'mission_id' => $this->mission->id,
            'church_id' => $church->id,
        ]);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/attendees', $payload($otherMissionChurch))
            ->assertJsonValidationErrors('church_id');
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/attendees', $payload($foreign))
            ->assertJsonValidationErrors('church_id');

        $otherUnion = Union::factory()->for($this->org)->create();
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/attendees', [...$payload($this->church()), 'union_id' => $otherUnion->id])
            ->assertJsonValidationErrors('mission_id');
    }

    public function test_update_validates_territory_only_when_touched(): void
    {
        $church = $this->church();
        $attendee = Attendee::factory()->create([
            'organization_id' => $this->org->id,
            'event_id' => Event::factory()->create(['organization_id' => $this->org->id])->id,
            'organization_level' => 'mission',
            'union_id' => $this->union->id,
            'mission_id' => $this->mission->id,
            'church_id' => null,
        ]);

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/attendees/{$attendee->id}", ['remarks' => 'ok'])->assertOk();

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/attendees/{$attendee->id}", ['mission_id' => $this->mission->id])
            ->assertJsonValidationErrors('church_id');

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/attendees/{$attendee->id}", ['church_id' => $church->id])->assertOk();

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/attendees/{$attendee->id}", ['organization_level' => 'union'])->assertOk();
        $this->assertNull($attendee->fresh()->church_id);
        $this->assertNull($attendee->fresh()->mission_id);
    }

    public function test_public_dropdowns_are_limited_to_the_event_organization(): void
    {
        $event = Event::factory()->create(['organization_id' => $this->org->id]);
        $church = $this->church();
        $foreignChurch = Church::factory()->create();
        Union::factory()->create();

        $this->getJson("/api/v1/public/unions?event={$event->invite_token}")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/public/missions?event={$event->invite_token}&union_id={$this->union->id}")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/public/churches?mission_id={$this->mission->id}&event={$event->invite_token}")
            ->assertOk()->assertJsonPath('data.0.id', $church->id)->assertJsonMissingPath('data.0.verified_at');
        $this->getJson("/api/v1/public/churches?mission_id={$foreignChurch->mission_id}&event={$event->invite_token}")
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/public/churches?mission_id={$this->mission->id}")->assertJsonValidationErrors('event');
        $this->getJson('/api/v1/public/unions?event=nope')->assertNotFound();
    }
}
