<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Church;
use App\Models\Mission;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AttendeeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_attendee_can_be_created_with_new_contact_fields(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $union = Union::factory()->for($org)->create();
        $mission = Mission::factory()->for($org)->for($union)->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendees', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'mobile_no' => '+1234567890',
            'email_address' => 'john@example.com',
            'remarks' => 'VIP attendee',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('attendees', [
            'first_name' => 'John',
            'mobile_no' => '+1234567890',
            'email_address' => 'john@example.com',
            'remarks' => 'VIP attendee',
        ]);
    }

    public function test_attendee_contact_fields_are_optional(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendees', [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('attendees', [
            'first_name' => 'Jane',
            'mobile_no' => null,
            'email_address' => null,
            'remarks' => null,
        ]);
    }

    public function test_email_address_must_be_valid(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendees', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email_address' => 'invalid-email',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('email_address');
    }

    public function test_mobile_no_has_max_length(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendees', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'mobile_no' => str_repeat('1', 21),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('mobile_no');
    }

    public function test_attendee_can_be_assigned_to_church(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $union = Union::factory()->for($org)->create();
        $mission = Mission::factory()->for($org)->for($union)->create();
        $church = Church::factory()->for($org)->for($union)->for($mission)->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendees', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'church_id' => $church->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('attendees', [
            'first_name' => 'John',
            'church_id' => $church->id,
        ]);
    }

    public function test_attendee_church_relation_loads_in_resource(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $union = Union::factory()->for($org)->create();
        $mission = Mission::factory()->for($org)->for($union)->create();
        $church = Church::factory()->for($org)->for($union)->for($mission)->create();
        $attendee = Attendee::factory()
            ->for($org)
            ->for($union)
            ->for($mission)
            ->for($church)
            ->create(['created_by' => $checker->id]);

        $response = $this->actingAs($checker, 'sanctum')->getJson("/api/v1/attendees?search={$attendee->first_name}");

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
        $this->assertEquals($church->id, $response->json('data.0.church_id'));
        $this->assertNotNull($response->json('data.0.church'));
    }

    public function test_attendee_contact_fields_round_trip_through_update(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $attendee = Attendee::factory()->for($org)->create(['created_by' => $checker->id]);

        $response = $this->actingAs($checker, 'sanctum')->putJson("/api/v1/attendees/{$attendee->id}", [
            'mobile_no' => '+1234567890',
            'email_address' => 'updated@example.com',
            'remarks' => 'Updated remarks',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.mobile_no', '+1234567890');
        $response->assertJsonPath('data.email_address', 'updated@example.com');
        $response->assertJsonPath('data.remarks', 'Updated remarks');
    }

    public function test_church_id_must_belong_to_same_org(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $checkerA = User::factory()->checker()->for($orgA)->create();
        $unionB = Union::factory()->for($orgB)->create();
        $missionB = Mission::factory()->for($orgB)->for($unionB)->create();
        $churchB = Church::factory()->for($orgB)->for($unionB)->for($missionB)->create();

        $response = $this->actingAs($checkerA, 'sanctum')->postJson('/api/v1/attendees', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'church_id' => $churchB->id,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('church_id');
    }
}
