<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class EventRegistrationDraftListTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_drafts_are_kept_out_of_the_registrations_list_and_listed_on_request(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($organization)->create();
        $event = Event::factory()->for($organization)->create();
        $started = Attendee::factory()->for($event)->for($organization)->create(['first_registered_at' => now()]);
        $draft = Attendee::factory()->for($event)->for($organization)->create(['mobile_no' => null]);

        $this->actingAs($admin, 'sanctum');

        $ids = fn (string $query) => collect($this->getJson("/api/v1/events/{$event->id}/registrations{$query}")->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$started->id], $ids(''));
        $this->assertSame([$draft->id], $ids('?registration_status=draft'));
        $this->getJson("/api/v1/events/{$event->id}/registrations?registration_status=bogus")->assertUnprocessable();
    }
}
