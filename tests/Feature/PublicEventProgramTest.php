<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventProgram;
use App\Models\EventProgramDay;
use App\Models\EventProgramItem;
use App\Models\EventProgramSection;
use App\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PublicEventProgramTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_public_program_returns_safe_schedule_for_published_event(): void
    {
        $event = Event::factory()->for(Organization::factory())->state(['status' => EventStatus::Published])->create();
        $program = EventProgram::factory()->for($event)->create(['is_public' => true, 'public_slug' => 'camp-meeting-program']);
        $day = EventProgramDay::factory()->for($program, 'program')->create(['date' => '2026-10-10']);
        $section = EventProgramSection::factory()->for($day, 'day')->create(['title' => 'Morning worship']);
        EventProgramItem::factory()->create([
            'event_program_id' => $program->id,
            'event_program_section_id' => $section->id,
            'participant_name' => 'Jane Doe',
        ]);

        $this->getJson('/api/v1/public/programs/camp-meeting-program')
            ->assertOk()
            ->assertJsonPath('data.name', $event->name)
            ->assertJsonPath('data.organization.name', $event->organization->name)
            ->assertJsonPath('data.program.days.0.sections.0.title', 'Morning worship')
            ->assertJsonPath('data.program.days.0.sections.0.items.0.participant_name', 'Jane Doe')
            ->assertJsonMissing(['attendee_id'])
            ->assertJsonMissing(['event_program_id']);
    }

    public function test_private_or_unpublished_program_returns_not_found(): void
    {
        $organization = Organization::factory()->create();
        $privateEvent = Event::factory()->for($organization)->state(['status' => EventStatus::Published])->create();
        EventProgram::factory()->for($privateEvent)->create(['is_public' => false, 'public_slug' => 'private-program']);

        foreach ([EventStatus::Draft, EventStatus::Cancelled, EventStatus::Completed] as $status) {
            $event = Event::factory()->for($organization)->state(['status' => $status])->create();
            EventProgram::factory()->for($event)->create(['is_public' => true, 'public_slug' => "{$status->value}-program"]);
            $this->getJson("/api/v1/public/programs/{$status->value}-program")->assertNotFound();
        }

        $this->getJson('/api/v1/public/programs/private-program')->assertNotFound();
        $this->getJson('/api/v1/public/programs/missing-program')->assertNotFound();
    }
}
