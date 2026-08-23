<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AttendeeDuplicateTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creating_attendee_with_duplicate_name_returns_conflict(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        Attendee::factory()->for($org)->create(['first_name' => 'Juan', 'middle_name' => null, 'last_name' => 'Dela Cruz']);

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendees', [
            'first_name' => 'juan',
            'last_name' => '  dela   cruz ',
        ]);

        $response->assertStatus(409);
        $this->assertSame(1, Attendee::count());
    }

    public function test_creating_attendee_with_override_bypasses_duplicate_check(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        Attendee::factory()->for($org)->create(['first_name' => 'Juan', 'middle_name' => null, 'last_name' => 'Dela Cruz']);

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendees', [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'override_duplicate' => true,
        ]);

        $response->assertCreated();
        $this->assertSame(2, Attendee::count());
    }

    public function test_check_duplicates_endpoint_finds_matches_without_creating(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        Attendee::factory()->for($org)->create(['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Reyes']);

        $response = $this->actingAs($checker, 'sanctum')->getJson('/api/v1/attendees/check-duplicates?'.http_build_query([
            'first_name' => 'ana',
            'last_name' => 'reyes',
        ]));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(1, Attendee::count());
    }
}
