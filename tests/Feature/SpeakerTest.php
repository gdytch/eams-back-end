<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventProgram;
use App\Models\EventProgramDay;
use App\Models\EventProgramItem;
use App\Models\EventProgramSection;
use App\Models\EventRegistration;
use App\Models\Organization;
use App\Models\Speaker;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SpeakerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $organization = Organization::factory()->create();
        $this->event = Event::factory()->for($organization)->create();
        $this->actingAs(User::factory()->orgAdmin()->for($organization)->create(), 'sanctum');
    }

    public function test_admin_creates_speaker_with_optimized_photo_and_can_update_visibility(): void
    {
        Storage::fake('public');

        $response = $this->post("/api/v1/events/{$this->event->id}/speakers", [
            'name' => 'Jane Doe',
            'designation' => 'Guest Speaker',
            'organization' => 'Hope Media',
            'bio' => 'A short biography.',
            'photo' => UploadedFile::fake()->image('speaker.png', 900, 900),
        ])->assertCreated()->assertJsonPath('data.name', 'Jane Doe');

        $speaker = Speaker::findOrFail($response->json('data.id'));
        foreach ($speaker->photo_paths as $path) {
            Storage::disk('public')->assertExists($path);
        }

        $this->putJson("/api/v1/events/{$this->event->id}/speaker-settings", ['speakers_public' => true])
            ->assertOk()
            ->assertJsonPath('data.speakers_public', true);
    }

    public function test_speaker_photo_must_be_a_png(): void
    {
        $this->post("/api/v1/events/{$this->event->id}/speakers", [
            'name' => 'Jane Doe',
            'designation' => 'Guest Speaker',
            'organization' => 'Hope Media',
            'bio' => 'A short biography.',
            'photo' => UploadedFile::fake()->image('speaker.jpg', 900, 900),
        ])->assertUnprocessable()->assertJsonValidationErrors('photo');
    }

    public function test_public_event_only_exposes_speakers_when_enabled(): void
    {
        $this->event->update(['status' => EventStatus::Published]);
        Speaker::factory()->for($this->event)->create(['name' => 'Public Speaker']);

        $this->getJson("/api/v1/public/event/{$this->event->invite_token}")
            ->assertOk()
            ->assertJsonCount(0, 'data.speakers');

        $this->event->update(['speakers_public' => true]);
        $this->getJson("/api/v1/public/event/{$this->event->invite_token}")
            ->assertOk()
            ->assertJsonPath('data.speakers.0.name', 'Public Speaker')
            ->assertJsonMissing(['event_id', 'photo_paths']);
    }

    public function test_public_speaker_includes_linked_program_schedule(): void
    {
        $this->event->update(['status' => EventStatus::Published, 'speakers_public' => true]);
        $program = EventProgram::factory()->for($this->event)->create();
        $speaker = Speaker::factory()->for($this->event)->create(['name' => 'Scheduled Speaker']);
        EventProgramItem::factory()->create([
            'event_program_id' => $program->id,
            'speaker_id' => $speaker->id,
            'date' => '2026-09-24',
            'start_time' => '09:30:00',
            'end_time' => '10:15:00',
            'part_title' => 'Opening message',
            'part_subtitle' => 'Faith and service',
            'part_description' => 'A welcome and introduction for attendees.',
        ]);
        EventProgramItem::factory()->create([
            'event_program_id' => $program->id,
            'speaker_id' => $speaker->id,
            'date' => '2026-09-24',
            'part_title' => 'Hidden title only',
            'part_subtitle' => null,
            'part_description' => null,
        ]);

        $this->getJson("/api/v1/public/event/{$this->event->invite_token}")
            ->assertOk()
            ->assertJsonCount(1, 'data.speakers.0.program_schedule')
            ->assertJsonPath('data.speakers.0.program_schedule.0.title', 'Opening message')
            ->assertJsonPath('data.speakers.0.program_schedule.0.topic', 'Faith and service')
            ->assertJsonPath('data.speakers.0.program_schedule.0.details', 'A welcome and introduction for attendees.')
            ->assertJsonPath('data.speakers.0.program_schedule.0.date', '2026-09-24')
            ->assertJsonPath('data.speakers.0.program_schedule.0.start_time', '09:30:00');
    }

    public function test_program_speaker_must_belong_to_event_and_deletion_keeps_participant_fallback(): void
    {
        $program = EventProgram::factory()->for($this->event)->create();
        $day = EventProgramDay::factory()->for($program, 'program')->create();
        $section = EventProgramSection::factory()->for($day, 'day')->create();
        $speaker = Speaker::factory()->for($this->event)->create(['name' => 'Linked Speaker']);
        $foreign = Speaker::factory()->create();

        $url = "/api/v1/events/{$this->event->id}/program/builder/parts";
        $this->postJson($url, ['parent_id' => $section->id, 'title' => 'Message', 'speaker_id' => $foreign->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('speaker_id');

        $itemId = $this->postJson($url, ['parent_id' => $section->id, 'title' => 'Message', 'speaker_id' => $speaker->id])
            ->assertCreated()
            ->assertJsonPath('data.participant_name', 'Linked Speaker')
            ->json('data.id');

        $this->deleteJson("/api/v1/events/{$this->event->id}/speakers/{$speaker->id}")->assertNoContent();
        $item = EventProgramItem::findOrFail($itemId);
        $this->assertNull($item->speaker_id);
        $this->assertSame('Linked Speaker', $item->participant_name);
    }

    public function test_attendee_can_only_list_speakers_for_registered_event(): void
    {
        $user = User::factory()->attendee()->create();
        $attendee = Attendee::factory()->create(['user_id' => $user->id]);
        EventRegistration::factory()->for($this->event)->for($attendee)->create();
        Speaker::factory()->for($this->event)->create(['name' => 'Registered Event Speaker']);
        $otherEvent = Event::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/attendee/events/{$this->event->id}/speakers")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Registered Event Speaker');

        $this->getJson("/api/v1/attendee/events/{$otherEvent->id}/speakers")->assertNotFound();
    }
}
