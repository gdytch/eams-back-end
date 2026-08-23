<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class OrganizationIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_org_admin_cannot_list_organizations(): void
    {
        $orgAdmin = User::factory()->orgAdmin()->for(Organization::factory())->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')->getJson('/api/v1/organizations');

        $response->assertForbidden();
    }

    public function test_super_admin_can_list_organizations(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        Organization::factory()->count(2)->create();

        $response = $this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/organizations');

        $response->assertOk();
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_org_admin_cannot_view_union_belonging_to_another_organization(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $orgAdminA = User::factory()->orgAdmin()->for($orgA)->create();
        $unionB = Union::factory()->for($orgB)->create();

        $response = $this->actingAs($orgAdminA, 'sanctum')->getJson("/api/v1/unions/{$unionB->id}");

        $response->assertNotFound();
    }

    public function test_org_admin_can_view_union_within_own_organization(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $union = Union::factory()->for($org)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')->getJson("/api/v1/unions/{$union->id}");

        $response->assertOk();
    }
}
