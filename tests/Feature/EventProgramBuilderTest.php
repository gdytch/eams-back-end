<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventProgram;
use App\Models\EventProgramDay;
use App\Models\EventProgramItem;
use App\Models\EventProgramSection;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EventProgramBuilderTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Event $event;

    private EventProgram $program;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        $org = Organization::factory()->create();
        $this->actingAs(User::factory()->orgAdmin()->for($org)->create(), 'sanctum');
        $this->event = Event::factory()->for($org)->create();
        $this->program = EventProgram::factory()->for($this->event)->create();
        $this->url = "/api/v1/events/{$this->event->id}/program";
    }

    private function section(): EventProgramSection
    {
        $day = EventProgramDay::factory()->create(['event_program_id' => $this->program->id]);

        return EventProgramSection::factory()->create(['event_program_day_id' => $day->id]);
    }

    public function test_nested_program_crud_and_optional_fields(): void
    {
        $this->putJson($this->url, ['title' => 'Conference'])->assertOk();
        $day = $this->postJson($this->url.'/builder/days', ['date' => '2026-06-25'])->assertCreated()->json('data.id');
        $section = $this->postJson($this->url.'/builder/sections', ['parent_id' => $day, 'title' => 'Opening'])->assertCreated()->json('data.id');
        $item = $this->postJson($this->url.'/builder/parts', ['parent_id' => $section, 'title' => 'Song', 'details' => 'Mission'])->assertCreated()->json('data.id');
        $this->putJson($this->url."/builder/parts/{$item}", ['title' => 'Speaker', 'participant_name' => 'Media team', 'attendee_id' => null, 'designation' => 'Director', 'details' => 'Topic', 'start_time' => '19:00'])->assertOk();
        $this->getJson($this->url)->assertOk()->assertJsonPath('data.title', 'Conference')->assertJsonPath('data.days.0.sections.0.items.0.title', 'Speaker')->assertJsonPath('data.days.0.sections.0.items.0.attendee_id', null);
        $this->putJson($this->url."/builder/days/{$day}", ['date' => '2026-06-26'])->assertOk();
        $this->assertSame('2026-06-26', EventProgramItem::findOrFail($item)->date->toDateString());
        $this->deleteJson($this->url."/builder/days/{$day}")->assertNoContent();
        $this->assertNull(EventProgramItem::find($item));
        $this->assertNull(EventProgramSection::find($section));
    }

    public function test_admin_can_publish_program_with_unique_editable_slug(): void
    {
        $this->putJson($this->url, [
            'title' => 'Camp Meeting',
            'description' => 'Schedule',
            'is_public' => true,
            'public_slug' => 'camp-meeting-program',
        ])->assertOk()
            ->assertJsonPath('data.is_public', true)
            ->assertJsonPath('data.public_slug', 'camp-meeting-program');

        $other = EventProgram::factory()->create(['public_slug' => 'taken-program']);
        $this->putJson($this->url, [
            'title' => 'Camp Meeting',
            'is_public' => true,
            'public_slug' => $other->public_slug,
        ])->assertUnprocessable()->assertJsonValidationErrors(['public_slug']);

        $this->actingAs(User::factory()->checker()->for($this->event->organization)->create(), 'sanctum')
            ->putJson($this->url, ['title' => 'No access', 'is_public' => false])
            ->assertForbidden();
    }

    public function test_program_slug_is_generated_and_gets_numeric_suffix_on_conflict(): void
    {
        $firstEvent = Event::factory()->for($this->event->organization)->create(['name' => 'Shared Event']);
        $first = EventProgram::factory()->for($firstEvent)->create();
        $otherEvent = Event::factory()->for($this->event->organization)->create(['name' => 'Shared Event']);
        $second = EventProgram::factory()->for($otherEvent)->create();

        $this->assertSame('shared-event', $first->public_slug);
        $this->assertSame('shared-event-2', $second->public_slug);
    }

    public function test_duplicates_have_new_ids_and_follow_original(): void
    {
        $section = $this->section();
        $other = EventProgramSection::factory()->create(['event_program_day_id' => $section->event_program_day_id, 'sort_order' => 1]);
        $item = EventProgramItem::factory()->create(['event_program_id' => $this->program->id, 'event_program_section_id' => $section->id]);
        $copy = $this->postJson($this->url."/builder/sections/{$section->id}/duplicate")->assertCreated()->json('data');
        $this->assertNotSame($section->id, $copy['id']);
        $this->assertNotSame($item->id, $copy['items'][0]['id']);
        $this->assertSame($item->participant_name, $copy['items'][0]['participant_name']);
        $this->assertSame(1, $copy['sort_order']);
        $this->assertSame(2, $other->fresh()->sort_order);
        $this->postJson($this->url."/builder/parts/{$item->id}/duplicate")->assertCreated()->assertJsonPath('data.sort_order', 1);
    }

    public function test_reorder_is_atomic_and_rejects_foreign_missing_or_duplicate_ids(): void
    {
        $section = $this->section();
        $items = EventProgramItem::factory()->count(2)->sequence(['order' => 0], ['order' => 1])->create(['event_program_id' => $this->program->id, 'event_program_section_id' => $section->id]);
        $url = $this->url.'/builder/parts/order';
        $this->putJson($url, ['parent_id' => $section->id, 'ids' => [$items[1]->id, $items[0]->id]])->assertNoContent();
        $this->assertSame(0, $items[1]->fresh()->order);
        foreach ([[$items[0]->id], [$items[0]->id, 999999], [$items[0]->id, $items[0]->id]] as $ids) {
            $this->putJson($url, ['parent_id' => $section->id, 'ids' => $ids])->assertUnprocessable();
            $this->assertSame(0, $items[1]->fresh()->order);
        }
        $this->putJson($this->url.'/builder/sections/order', ['parent_id' => $section->event_program_day_id, 'ids' => [$section->id]])->assertNoContent();
        $this->putJson($this->url.'/builder/days/order', ['ids' => [$section->event_program_day_id]])->assertNoContent();
    }

    public function test_validation_and_attendee_scope(): void
    {
        $section = $this->section();
        $url = $this->url.'/builder/parts';
        $this->postJson($url, ['parent_id' => $section->id, 'title' => '', 'start_time' => '25:00'])->assertUnprocessable()->assertJsonValidationErrors(['title', 'start_time']);
        $attendee = Attendee::factory()->create(['organization_id' => $this->event->organization_id]);
        $this->postJson($url, ['parent_id' => $section->id, 'title' => 'Speaker', 'attendee_id' => $attendee->id])->assertCreated();
        $foreign = Attendee::factory()->create();
        $this->postJson($url, ['parent_id' => $section->id, 'title' => 'Speaker', 'attendee_id' => $foreign->id])->assertUnprocessable();
        $date = $section->day->date->toDateString();
        $this->postJson($this->url.'/builder/days', ['date' => $date])->assertUnprocessable();
    }

    public function test_all_mutations_require_event_management_and_scope(): void
    {
        $section = $this->section();
        $foreign = EventProgramSection::factory()->create();
        $this->putJson($this->url."/builder/sections/{$foreign->id}", ['title' => 'No'])->assertNotFound();
        $this->postJson($this->url.'/builder/parts', ['parent_id' => $foreign->id, 'title' => 'No'])->assertNotFound();
        $this->deleteJson($this->url."/builder/sections/{$foreign->id}")->assertNotFound();
        $checker = User::factory()->checker()->create(['organization_id' => $this->event->organization_id]);
        $this->actingAs($checker, 'sanctum');
        $this->postJson($this->url.'/builder/days', ['date' => '2026-06-25'])->assertForbidden();
        $this->putJson($this->url."/builder/sections/{$section->id}", ['title' => 'No'])->assertForbidden();
        $this->deleteJson($this->url."/builder/sections/{$section->id}")->assertForbidden();
        $this->postJson($this->url."/builder/sections/{$section->id}/duplicate")->assertForbidden();
        $this->putJson($this->url.'/builder/days/order', ['ids' => [1]])->assertForbidden();
        $this->putJson($this->url, ['title' => 'No'])->assertForbidden();
    }

    public function test_backfill_preserves_legacy_data_and_photos(): void
    {
        $item = EventProgramItem::factory()->create(['event_program_id' => $this->program->id, 'date' => null, 'photo_paths' => [['original' => 'old.jpg']]]);
        $migration = require glob(database_path('migrations/*backfill_event_program_hierarchy.php'))[0];
        $migration->up();
        $item->refresh();
        $this->assertNotNull($item->event_program_section_id);
        $this->assertSame([['original' => 'old.jpg']], $item->photo_paths);
        $this->assertSame($this->event->start_date->toDateString(), $item->section->day->date->toDateString());
        $this->assertSame(1, DB::table('event_program_days')->where('event_program_id', $this->program->id)->count());
    }

    public function test_legacy_writes_are_visible_in_builder_and_date_edits_regroup_items(): void
    {
        $id = $this->postJson($this->url.'/items', ['part_title' => 'Legacy speaker', 'date' => '2026-06-25'])
            ->assertCreated()->json('data.id');
        $this->getJson($this->url)->assertJsonPath('data.days.0.sections.0.items.0.title', 'Legacy speaker');
        $this->putJson($this->url.'/items/'.$id, ['date' => '2026-06-26'])->assertOk();
        $item = EventProgramItem::findOrFail($id);
        $this->assertSame('2026-06-26', $item->section->day->date->toDateString());
        $this->assertSame('Legacy speaker', $item->part_title);
    }

    public function test_deleting_nodes_cleans_up_descendant_photos(): void
    {
        Storage::fake('public');
        foreach (['days', 'sections', 'parts'] as $kind) {
            $section = $this->section();
            $path = "program/{$kind}.jpg";
            Storage::disk('public')->put($path, 'image');
            $item = EventProgramItem::factory()->create([
                'event_program_id' => $this->program->id, 'event_program_section_id' => $section->id,
                'photo_paths' => [['original' => $path]],
            ]);
            $id = match ($kind) {
                'days' => $section->event_program_day_id, 'sections' => $section->id, 'parts' => $item->id
            };
            $this->deleteJson($this->url."/builder/{$kind}/{$id}")->assertNoContent();
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_super_admin_attendee_suggestions_filter_before_pagination(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create(), 'sanctum');
        Attendee::factory()->count(16)->create(['first_name' => 'Speaker', 'last_name' => 'A']);
        $match = Attendee::factory()->create(['organization_id' => $this->event->organization_id, 'first_name' => 'Speaker', 'last_name' => 'Z']);
        $this->getJson('/api/v1/attendees?search=Speaker&per_page=15&organization_id='.$this->event->organization_id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
    }
}
