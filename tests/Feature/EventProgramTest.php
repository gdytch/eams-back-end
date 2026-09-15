<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventProgram;
use App\Models\EventProgramItem;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class EventProgramTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_org_admin_can_create_program(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->postJson("/api/v1/events/{$event->id}/program");

        $response->assertStatus(201);
        $response->assertJsonPath('data.id', $response->json('data.id'));
        $response->assertJsonPath('data.event_id', $event->id);

        $this->assertTrue($event->program()->exists());
    }

    public function test_non_admin_cannot_create_program(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')
            ->postJson("/api/v1/events/{$event->id}/program");

        $response->assertStatus(403);
    }

    public function test_cross_org_cannot_create_program(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org2)->create();
        $event = Event::factory()->for($org1)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->postJson("/api/v1/events/{$event->id}/program");

        $response->assertStatus(404);
    }

    public function test_create_program_when_one_exists_returns_409(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        EventProgram::factory()->for($event)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->postJson("/api/v1/events/{$event->id}/program");

        $response->assertStatus(409);
    }

    public function test_org_admin_can_view_program(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $program = EventProgram::factory()->for($event)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->getJson("/api/v1/events/{$event->id}/program");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $program->id);
        $response->assertJsonPath('data.event_id', $event->id);
        $response->assertJsonStructure(['data' => ['id', 'event_id', 'items', 'created_at', 'updated_at']]);
    }

    public function test_program_not_found_returns_404(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->getJson("/api/v1/events/{$event->id}/program");

        $response->assertStatus(404);
    }

    public function test_cross_org_cannot_view_program(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org2)->create();
        $event = Event::factory()->for($org1)->create();
        EventProgram::factory()->for($event)->create();

        $response = $this->actingAs($checker, 'sanctum')
            ->getJson("/api/v1/events/{$event->id}/program");

        $response->assertStatus(404);
    }

    public function test_org_admin_can_delete_program(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        EventProgram::factory()->for($event)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->deleteJson("/api/v1/events/{$event->id}/program");

        $response->assertStatus(204);
        $this->assertFalse($event->program()->exists());
    }

    public function test_non_admin_cannot_delete_program(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        EventProgram::factory()->for($event)->create();

        $response = $this->actingAs($checker, 'sanctum')
            ->deleteJson("/api/v1/events/{$event->id}/program");

        $response->assertStatus(403);
    }

    public function test_super_admin_can_create_program(): void
    {
        $org = Organization::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $event = Event::factory()->for($org)->create();

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/v1/events/{$event->id}/program");

        $response->assertStatus(201);
    }

    public function test_program_includes_items_when_loaded(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $program = EventProgram::factory()->for($event)->create();
        EventProgramItem::factory()->create(['event_program_id' => $program->id]);
        EventProgramItem::factory()->create(['event_program_id' => $program->id]);
        EventProgramItem::factory()->create(['event_program_id' => $program->id]);

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->getJson("/api/v1/events/{$event->id}/program");

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data.items');
    }

    public function test_unauthenticated_cannot_access_program(): void
    {
        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->create();

        $response = $this->getJson("/api/v1/events/{$event->id}/program");

        $response->assertStatus(401);
    }
}
