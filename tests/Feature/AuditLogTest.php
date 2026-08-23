<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creating_an_attendee_writes_an_audit_log_entry(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendees', [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $org->id,
            'user_id' => $checker->id,
            'action' => 'attendee.created',
            'auditable_type' => 'App\Models\Attendee',
        ]);
    }

    public function test_checker_cannot_view_audit_logs(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')->getJson('/api/v1/audit-logs');

        $response->assertForbidden();
    }

    public function test_org_admin_only_sees_their_own_organizations_audit_logs(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminA = User::factory()->orgAdmin()->for($orgA)->create();

        AuditLog::factory()->create(['organization_id' => $orgA->id, 'action' => 'attendee.created']);
        AuditLog::factory()->create(['organization_id' => $orgB->id, 'action' => 'attendee.created']);

        $response = $this->actingAs($adminA, 'sanctum')->getJson('/api/v1/audit-logs');

        $response->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_super_admin_sees_audit_logs_across_all_organizations(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        AuditLog::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        AuditLog::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $response = $this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/audit-logs');

        $response->assertOk();
        $this->assertSame(2, $response->json('meta.total'));
    }
}
