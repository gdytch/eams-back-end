<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\Mission;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class SloppySsoTerritoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_sso_territory_fields_are_ignored_and_the_linked_attendee_keeps_its_territory(): void
    {
        $organization = Organization::factory()->create();
        $oldUnion = Union::factory()->for($organization)->create();
        $newUnion = Union::factory()->for($organization)->create();
        $mission = Mission::factory()->for($organization)->for($newUnion)->create();
        $user = User::factory()->create(['email' => 'linked@example.com', 'organization_id' => $organization->id]);
        $attendee = Attendee::factory()->for(Event::factory()->for($organization)->create())->create([
            'email_address' => $user->email,
            'user_id' => null,
            'organization_level' => 'union',
            'union_id' => $oldUnion->id,
            'mission_id' => null,
        ]);
        $this->mockGoogleUser($user->email, 'same-org-subject');

        $this->postJson('/api/v1/auth/sso/google', [
            'token' => 'provider-token',
            'organization_id' => $organization->id,
            'organization_level' => 'mission',
            'union_id' => $newUnion->id,
            'mission_id' => $mission->id,
        ])->assertOk();

        $this->assertSame($user->id, $attendee->fresh()->user_id);
        $this->assertSame('union', $attendee->fresh()->organization_level->value);
        $this->assertSame($oldUnion->id, $attendee->fresh()->union_id);
        $this->assertNull($attendee->fresh()->mission_id);
    }

    public function test_territory_from_a_different_organization_does_not_overwrite_attendee_or_set_the_user_organization(): void
    {
        $attendeeOrganization = Organization::factory()->create();
        $requestOrganization = Organization::factory()->create();
        $attendeeUnion = Union::factory()->for($attendeeOrganization)->create();
        $requestUnion = Union::factory()->for($requestOrganization)->create();
        $requestMission = Mission::factory()->for($requestOrganization)->for($requestUnion)->create();
        $user = User::factory()->create(['email' => 'linked@example.com', 'organization_id' => null]);
        $attendee = Attendee::factory()->for(Event::factory()->for($attendeeOrganization)->create())->create([
            'email_address' => $user->email,
            'user_id' => null,
            'organization_level' => 'union',
            'union_id' => $attendeeUnion->id,
            'mission_id' => null,
        ]);
        $this->mockGoogleUser($user->email, 'other-org-subject');

        $this->postJson('/api/v1/auth/sso/google', [
            'token' => 'provider-token',
            'organization_id' => $requestOrganization->id,
            'organization_level' => 'mission',
            'union_id' => $requestUnion->id,
            'mission_id' => $requestMission->id,
        ])->assertOk();

        $this->assertSame($user->id, $attendee->fresh()->user_id);
        $this->assertNull($user->fresh()->organization_id);
        $this->assertSame('union', $attendee->fresh()->organization_level->value);
        $this->assertSame($attendeeUnion->id, $attendee->fresh()->union_id);
        $this->assertNull($attendee->fresh()->mission_id);
    }

    private function mockGoogleUser(string $email, string $providerId): void
    {
        $socialiteUser = new SocialiteUser;
        $socialiteUser->id = $providerId;
        $socialiteUser->email = $email;
        $socialiteUser->name = 'Linked Attendee';
        $socialiteUser->setRaw(['verified_email' => true, 'hd' => 'example.com']);

        Socialite::shouldReceive('driver')
            ->once()
            ->with('google')
            ->andReturn($driver = \Mockery::mock());
        $driver->shouldReceive('stateless')->once()->andReturnSelf();
        $driver->shouldReceive('userFromToken')->once()->with('provider-token')->andReturn($socialiteUser);
    }
}
