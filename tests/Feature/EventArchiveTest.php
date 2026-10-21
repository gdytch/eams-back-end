<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class EventArchiveTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_super_admin_archives_an_event(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $event = Event::factory()->create();

        $response = $this->actingAs($superAdmin, 'sanctum')->postJson("/api/v1/events/{$event->id}/archive");

        $response->assertOk()->assertJsonPath('data.archived_at', fn ($value) => $value !== null);
        $event->refresh();
        $this->assertTrue($event->isArchived());
        $this->assertSame($superAdmin->id, $event->archived_by);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'event.archived',
            'auditable_id' => $event->id,
            'user_id' => $superAdmin->id,
        ]);
    }

    public function test_events_can_be_archived_in_any_status(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        foreach (EventStatus::cases() as $status) {
            $event = Event::factory()->create(['status' => $status]);

            $this->actingAs($superAdmin, 'sanctum')
                ->postJson("/api/v1/events/{$event->id}/archive")
                ->assertOk();
        }
    }

    public function test_unarchive_clears_archive_columns_and_keeps_status(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $event = Event::factory()->create(['status' => EventStatus::Completed]);

        $this->actingAs($superAdmin, 'sanctum')->postJson("/api/v1/events/{$event->id}/archive")->assertOk();
        $response = $this->actingAs($superAdmin, 'sanctum')->postJson("/api/v1/events/{$event->id}/unarchive");

        $response->assertOk()->assertJsonPath('data.archived_at', null);
        $event->refresh();
        $this->assertNull($event->archived_at);
        $this->assertNull($event->archived_by);
        $this->assertSame(EventStatus::Completed, $event->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'event.unarchived')->where('auditable_id', $event->id)->count());
    }

    public function test_org_admin_cannot_archive_or_unarchive(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $archived = Event::factory()->for($org)->create(['archived_at' => now()]);

        $this->actingAs($orgAdmin, 'sanctum')->postJson("/api/v1/events/{$event->id}/archive")->assertForbidden();
        $this->actingAs($orgAdmin, 'sanctum')->postJson("/api/v1/events/{$archived->id}/unarchive")->assertForbidden();
        $this->assertNull($event->refresh()->archived_at);
        $this->assertNotNull($archived->refresh()->archived_at);
    }

    public function test_checker_cannot_archive_or_unarchive(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $archived = Event::factory()->for($org)->create(['archived_at' => now()]);

        $this->actingAs($checker, 'sanctum')->postJson("/api/v1/events/{$event->id}/archive")->assertForbidden();
        $this->actingAs($checker, 'sanctum')->postJson("/api/v1/events/{$archived->id}/unarchive")->assertForbidden();
    }

    public function test_events_cannot_be_deleted(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $event = Event::factory()->create();

        $response = $this->actingAs($superAdmin, 'sanctum')->deleteJson("/api/v1/events/{$event->id}");

        $this->assertContains($response->status(), [404, 405]);
        $this->assertDatabaseHas('events', ['id' => $event->id]);
    }

    public function test_event_list_hides_archived_events_by_default(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $active = Event::factory()->for($org)->create();
        $archived = Event::factory()->for($org)->create(['archived_at' => now()]);

        $response = $this->actingAs($orgAdmin, 'sanctum')->getJson('/api/v1/events');

        $response->assertOk();
        $this->assertSame([$active->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertNotContains($archived->id, collect($response->json('data'))->pluck('id'));
    }

    public function test_archived_filter_lists_only_archived_events(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        Event::factory()->for($org)->create();
        $archived = Event::factory()->for($org)->create(['archived_at' => now()]);

        $response = $this->actingAs($orgAdmin, 'sanctum')->getJson('/api/v1/events?archived=1');

        $response->assertOk();
        $this->assertSame([$archived->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertNotNull($response->json('data.0.archived_at'));
    }
}
