<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventProgram;
use App\Models\EventProgramItem;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EventProgramItemTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_org_admin_can_create_item(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $program = EventProgram::factory()->for($event)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->postJson("/api/v1/events/{$event->id}/program/items", [
                'date' => '2026-09-20',
                'start_time' => '09:00',
                'end_time' => '10:00',
                'part_title' => 'Opening Remarks',
                'participant_name' => 'John Doe',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.part_title', 'Opening Remarks');
        $response->assertJsonPath('data.participant_name', 'John Doe');
    }

    public function test_create_item_without_program_returns_404(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->postJson("/api/v1/events/{$event->id}/program/items", [
                'date' => '2026-09-20',
                'start_time' => '09:00',
                'end_time' => '10:00',
            ]);

        $response->assertStatus(404);
    }

    public function test_non_admin_cannot_create_item(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $program = EventProgram::factory()->for($event)->create();

        $response = $this->actingAs($checker, 'sanctum')
            ->postJson("/api/v1/events/{$event->id}/program/items", [
                'date' => '2026-09-20',
                'start_time' => '09:00',
                'end_time' => '10:00',
            ]);

        $response->assertStatus(403);
    }

    public function test_cross_org_cannot_create_item(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org2)->create();
        $event = Event::factory()->for($org1)->create();
        $program = EventProgram::factory()->for($event)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->postJson("/api/v1/events/{$event->id}/program/items", [
                'date' => '2026-09-20',
                'start_time' => '09:00',
                'end_time' => '10:00',
            ]);

        $response->assertStatus(404);
    }

    public function test_list_items_for_program(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $program = EventProgram::factory()->for($event)->create();
        EventProgramItem::factory()->create(['event_program_id' => $program->id]);
        EventProgramItem::factory()->create(['event_program_id' => $program->id]);
        EventProgramItem::factory()->create(['event_program_id' => $program->id]);

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->getJson("/api/v1/events/{$event->id}/program/items");

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data');
    }

    public function test_list_items_without_program_returns_404(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->getJson("/api/v1/events/{$event->id}/program/items");

        $response->assertStatus(404);
    }

    public function test_get_single_item(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $program = EventProgram::factory()->for($event)->create();
        $item = EventProgramItem::factory()->create([
            'event_program_id' => $program->id,
            'part_title' => 'Keynote',
        ]);

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->getJson("/api/v1/events/{$event->id}/program/items/{$item->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $item->id);
        $response->assertJsonPath('data.part_title', 'Keynote');
    }

    public function test_update_item(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $program = EventProgram::factory()->for($event)->create();
        $item = EventProgramItem::factory()->create(['event_program_id' => $program->id]);

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->putJson("/api/v1/events/{$event->id}/program/items/{$item->id}", [
                'part_title' => 'Updated Title',
                'participant_name' => 'Jane Smith',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.part_title', 'Updated Title');
        $response->assertJsonPath('data.participant_name', 'Jane Smith');
    }

    public function test_delete_item(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $program = EventProgram::factory()->for($event)->create();
        $item = EventProgramItem::factory()->create(['event_program_id' => $program->id]);

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->deleteJson("/api/v1/events/{$event->id}/program/items/{$item->id}");

        $response->assertStatus(204);
        $this->assertFalse(EventProgramItem::where('id', $item->id)->exists());
    }

    public function test_non_admin_cannot_delete_item(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $program = EventProgram::factory()->for($event)->create();
        $item = EventProgramItem::factory()->create(['event_program_id' => $program->id]);

        $response = $this->actingAs($checker, 'sanctum')
            ->deleteJson("/api/v1/events/{$event->id}/program/items/{$item->id}");

        $response->assertStatus(403);
    }

    public function test_item_from_wrong_program_returns_404(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event1 = Event::factory()->for($org)->create();
        $event2 = Event::factory()->for($org)->create();
        $program1 = EventProgram::factory()->for($event1)->create();
        $program2 = EventProgram::factory()->for($event2)->create();
        $item = EventProgramItem::factory()->create(['event_program_id' => $program2->id]);

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->getJson("/api/v1/events/{$event1->id}/program/items/{$item->id}");

        $response->assertStatus(404);
    }

    public function test_item_response_includes_photo_urls(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $program = EventProgram::factory()->for($event)->create();
        $item = EventProgramItem::factory()->create(['event_program_id' => $program->id]);

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->getJson("/api/v1/events/{$event->id}/program/items/{$item->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'event_program_id',
                'date',
                'start_time',
                'end_time',
                'part_title',
                'part_subtitle',
                'part_description',
                'participant_name',
                'participant_description',
                'participant_photo_urls',
                'part_remarks',
                'created_at',
                'updated_at',
            ],
        ]);
    }

    public function test_upload_photo_to_item(): void
    {
        Storage::fake('public');

        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $program = EventProgram::factory()->for($event)->create();
        $item = EventProgramItem::factory()->create(['event_program_id' => $program->id]);

        // Create a minimal valid JPEG image (1x1 pixel)
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->postJson("/api/v1/events/{$event->id}/program/items/{$item->id}/photos", [
                'photo' => 'data:image/png;base64,'.base64_encode($image),
            ]);

        $response->assertStatus(200);
        $response->assertJsonIsArray('data.participant_photo_urls');

        $item->refresh();
        $this->assertNotNull($item->photo_paths);
        $this->assertIsArray($item->photo_paths);
        $this->assertCount(1, $item->photo_paths);
    }

    public function test_remove_photo_from_item_by_index(): void
    {
        Storage::fake('public');

        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $program = EventProgram::factory()->for($event)->create();

        $item = EventProgramItem::factory()
            ->create([
                'event_program_id' => $program->id,
                'photo_paths' => [
                    ['sm' => 'path/to/photo1-sm.webp', 'md' => 'path/to/photo1-md.webp', 'original' => 'path/to/photo1-original.webp'],
                    ['sm' => 'path/to/photo2-sm.webp', 'md' => 'path/to/photo2-md.webp', 'original' => 'path/to/photo2-original.webp'],
                ],
            ]);

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->deleteJson("/api/v1/events/{$event->id}/program/items/{$item->id}/photos", [
                'index' => 0,
            ]);

        $response->assertStatus(200);

        $item->refresh();
        $this->assertCount(1, $item->photo_paths);
    }

    public function test_remove_photo_with_invalid_index(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $program = EventProgram::factory()->for($event)->create();
        $item = EventProgramItem::factory()->create(['event_program_id' => $program->id]);

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->deleteJson("/api/v1/events/{$event->id}/program/items/{$item->id}/photos", [
                'index' => 999,
            ]);

        $response->assertStatus(422);
    }

    public function test_unauthenticated_cannot_access_items(): void
    {
        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->create();

        $response = $this->getJson("/api/v1/events/{$event->id}/program/items");

        $response->assertStatus(401);
    }
}
