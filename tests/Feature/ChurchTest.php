<?php

namespace Tests\Feature;

use App\Models\Church;
use App\Models\Mission;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ChurchTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_org_admin_can_list_churches(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $union = Union::factory()->for($org)->create();
        $mission = Mission::factory()->for($org)->for($union)->create();
        $church = Church::factory()->for($org)->for($union)->for($mission)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')->getJson('/api/v1/churches');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $church->id);
    }

    public function test_org_admin_can_create_church(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $union = Union::factory()->for($org)->create();
        $mission = Mission::factory()->for($org)->for($union)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')->postJson('/api/v1/churches', [
            'union_id' => $union->id,
            'mission_id' => $mission->id,
            'name' => 'Test Church',
            'address' => '123 Main St',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Test Church');
        $this->assertDatabaseHas('churches', ['name' => 'Test Church']);
    }

    public function test_checker_cannot_create_church(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $union = Union::factory()->for($org)->create();
        $mission = Mission::factory()->for($org)->for($union)->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/churches', [
            'union_id' => $union->id,
            'mission_id' => $mission->id,
            'name' => 'Test Church',
        ]);

        $response->assertForbidden();
    }

    public function test_org_admin_can_view_church(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $union = Union::factory()->for($org)->create();
        $mission = Mission::factory()->for($org)->for($union)->create();
        $church = Church::factory()->for($org)->for($union)->for($mission)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')->getJson("/api/v1/churches/{$church->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $church->id);
    }

    public function test_org_admin_can_update_church(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $union = Union::factory()->for($org)->create();
        $mission = Mission::factory()->for($org)->for($union)->create();
        $church = Church::factory()->for($org)->for($union)->for($mission)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')->putJson("/api/v1/churches/{$church->id}", [
            'name' => 'Updated Church',
            'address' => 'New Address',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Updated Church');
        $this->assertDatabaseHas('churches', ['id' => $church->id, 'name' => 'Updated Church']);
    }

    public function test_org_admin_can_delete_church(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $union = Union::factory()->for($org)->create();
        $mission = Mission::factory()->for($org)->for($union)->create();
        $church = Church::factory()->for($org)->for($union)->for($mission)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')->deleteJson("/api/v1/churches/{$church->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('churches', ['id' => $church->id]);
    }

    public function test_required_fields_validation(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')->postJson('/api/v1/churches', [
            'name' => 'Test Church',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['union_id', 'mission_id']);
    }

    public function test_mission_must_belong_to_same_org(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $orgAdminA = User::factory()->orgAdmin()->for($orgA)->create();
        $unionA = Union::factory()->for($orgA)->create();
        $unionB = Union::factory()->for($orgB)->create();
        $missionB = Mission::factory()->for($orgB)->for($unionB)->create();

        $response = $this->actingAs($orgAdminA, 'sanctum')->postJson('/api/v1/churches', [
            'union_id' => $unionA->id,
            'mission_id' => $missionB->id,
            'name' => 'Test Church',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('mission_id');
    }
}
