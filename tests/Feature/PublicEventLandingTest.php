<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PublicEventLandingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_public_event_landing_page_returns_published_event_data(): void
    {
        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->state(['status' => EventStatus::Published])->create();
        EventSession::factory()->for($event)->create();

        $response = $this->getJson("/api/v1/public/events/{$event->invite_token}");

        $response->assertOk()->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'description',
                'start_date',
                'end_date',
                'venue',
                'status',
                'organization' => ['id', 'name'],
                'sessions' => [['id', 'name', 'session_date', 'start_time', 'end_time']],
            ],
        ]);
        $this->assertSame($event->id, $response->json('data.id'));
    }

    public function test_public_event_landing_page_returns_404_for_draft_event(): void
    {
        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->state(['status' => EventStatus::Draft])->create();

        $response = $this->getJson("/api/v1/public/events/{$event->invite_token}");

        $response->assertNotFound();
    }

    public function test_public_event_landing_page_returns_404_for_cancelled_event(): void
    {
        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->state(['status' => EventStatus::Cancelled])->create();

        $response = $this->getJson("/api/v1/public/events/{$event->invite_token}");

        $response->assertNotFound();
    }

    public function test_public_event_landing_page_returns_404_for_completed_event(): void
    {
        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->state(['status' => EventStatus::Completed])->create();

        $response = $this->getJson("/api/v1/public/events/{$event->invite_token}");

        $response->assertNotFound();
    }

    public function test_public_event_landing_page_returns_404_for_invalid_token(): void
    {
        $response = $this->getJson('/api/v1/public/events/invalid-token-12345');

        $response->assertNotFound();
    }

    public function test_public_event_landing_does_not_expose_sensitive_fields(): void
    {
        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->state(['status' => EventStatus::Published])->create();

        $response = $this->getJson("/api/v1/public/events/{$event->invite_token}");

        $this->assertNull($response->json('data.organization_id'));
        $this->assertNull($response->json('data.created_by'));
        $this->assertNull($response->json('data.id_card_background_path'));
    }
}
