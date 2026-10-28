<?php

namespace Tests\Feature;

use App\Enums\OrganizationLevel;
use App\Models\Attendee;
use App\Models\Breakout;
use App\Models\Certificate;
use App\Models\Church;
use App\Models\Event;
use App\Models\Mission;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CertificateAudienceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $org;

    private Event $event;

    private Certificate $certificate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->event = Event::factory()->for($this->org)->create();
        $this->certificate = Certificate::factory()->for($this->event)->create();
        $this->actingAs(User::factory()->orgAdmin()->for($this->org)->create(), 'sanctum');
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/events/{$this->event->id}/certificates/{$this->certificate->id}".$suffix;
    }

    private function audienceCount(): int
    {
        return $this->getJson($this->url('/audience-count'))->assertOk()->json('data.count');
    }

    private function union(): Union
    {
        return Union::factory()->create(['organization_id' => $this->org->id]);
    }

    private function mission(?Union $union = null): Mission
    {
        return Mission::factory()->create(['union_id' => ($union ?? $this->union())->id, 'organization_id' => $this->org->id]);
    }

    /** A Fully registered union-level Attendee of the event. */
    private function unionAttendee(Union $union, ?Event $event = null): Attendee
    {
        return Attendee::factory()->for($event ?? $this->event)->create([
            'organization_level' => OrganizationLevel::Union, 'union_id' => $union->id,
        ]);
    }

    /** A Fully registered mission-level Attendee of the event. */
    private function missionAttendee(Mission $mission, ?Event $event = null): Attendee
    {
        return Attendee::factory()->for($event ?? $this->event)->create([
            'organization_level' => OrganizationLevel::Mission,
            'union_id' => $mission->union_id, 'mission_id' => $mission->id,
            'church_id' => Church::factory()->create(['organization_id' => $this->org->id, 'union_id' => $mission->union_id, 'mission_id' => $mission->id])->id,
        ]);
    }

    public function test_release_settings_default_and_round_trip(): void
    {
        $this->certificate->update(['release_at' => null]);

        $this->getJson($this->url())
            ->assertJsonPath('data.release_rule', 'everyone')
            ->assertJsonPath('data.include_breakouts', false)
            ->assertJsonPath('data.release_at', null)
            ->assertJsonPath('data.audience.union_ids', [])
            ->assertJsonPath('data.audience.mission_ids', [])
            ->assertJsonPath('data.audience.attendee_ids', []);

        $union = $this->union();
        $mission = $this->mission($union);
        $attendee = $this->unionAttendee($union);

        $this->putJson($this->url(), [
            'release_rule' => 'rate_75', 'include_breakouts' => true, 'release_at' => '2027-01-05T09:30:00Z',
            'audience' => ['union_ids' => [$union->id], 'mission_ids' => [$mission->id], 'attendee_ids' => [$attendee->id]],
        ])->assertOk()
            ->assertJsonPath('data.release_rule', 'rate_75')
            ->assertJsonPath('data.include_breakouts', true)
            ->assertJsonPath('data.audience.union_ids', [$union->id])
            ->assertJsonPath('data.audience.mission_ids', [$mission->id])
            ->assertJsonPath('data.audience.attendee_ids', [$attendee->id]);

        $this->assertSame('2027-01-05 09:30:00', $this->certificate->fresh()->release_at->utc()->format('Y-m-d H:i:s'));

        $this->putJson($this->url(), ['release_at' => null])->assertOk()->assertJsonPath('data.release_at', null);
    }

    public function test_publishing_requires_a_release_time(): void
    {
        $this->postJson($this->url('/template'), ['template' => UploadedFile::fake()->image('t.png', 600, 400)])->assertOk();
        $this->certificate->update(['release_at' => null]);

        $this->postJson($this->url('/publish'))->assertUnprocessable()->assertJsonValidationErrors('release_at');
        $this->assertFalse($this->certificate->fresh()->isPublished());

        $this->putJson($this->url(), ['release_at' => '2027-01-05T09:30:00Z'])->assertOk();
        $this->postJson($this->url('/publish'))->assertOk();
    }

    public function test_a_published_certificate_keeps_its_release_time(): void
    {
        $this->postJson($this->url('/template'), ['template' => UploadedFile::fake()->image('t.png', 600, 400)])->assertOk();
        $this->postJson($this->url('/publish'))->assertOk();

        $this->putJson($this->url(), ['release_at' => null])->assertUnprocessable()->assertJsonValidationErrors('release_at');
    }

    public function test_every_release_rule_is_accepted_and_unknown_ones_rejected(): void
    {
        foreach (['everyone', 'checked_in', 'rate_50', 'rate_75'] as $rule) {
            $this->putJson($this->url(), ['release_rule' => $rule])->assertOk()->assertJsonPath('data.release_rule', $rule);
        }

        $this->putJson($this->url(), ['release_rule' => 'rate_90'])->assertJsonValidationErrors('release_rule');
    }

    public function test_audience_ids_must_belong_to_the_organization_and_the_event(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreignUnion = Union::factory()->create(['organization_id' => $otherOrg->id]);
        $foreignMission = Mission::factory()->create(['union_id' => $foreignUnion->id, 'organization_id' => $otherOrg->id]);
        $otherEvent = Event::factory()->for($this->org)->create();
        $otherEventAttendee = $this->unionAttendee($this->union(), $otherEvent);

        $this->putJson($this->url(), [
            'audience' => [
                'union_ids' => [$foreignUnion->id],
                'mission_ids' => [$foreignMission->id],
                'attendee_ids' => [$otherEventAttendee->id],
            ],
        ])->assertJsonValidationErrors(['audience.union_ids.0', 'audience.mission_ids.0', 'audience.attendee_ids.0']);
    }

    public function test_empty_selection_counts_every_fully_registered_attendee_of_the_event(): void
    {
        $union = $this->union();
        $this->unionAttendee($union);
        $this->missionAttendee($this->mission());
        Attendee::factory()->for($this->event)->draft()->create(['union_id' => $union->id]);
        $this->unionAttendee($union, Event::factory()->for($this->org)->create());

        $this->assertSame(2, $this->audienceCount());
    }

    public function test_selecting_a_union_covers_only_union_level_attendees_of_that_union(): void
    {
        $union = $this->union();
        $other = $this->union();
        $mission = $this->mission($union);
        $this->unionAttendee($union);
        $this->unionAttendee($union);
        $this->unionAttendee($other);
        $this->missionAttendee($mission); // same union, but mission level: not covered

        $this->certificate->update(['audience_union_ids' => [$union->id]]);

        $this->assertSame(2, $this->audienceCount());
    }

    public function test_selecting_a_mission_covers_only_mission_level_attendees_of_that_mission(): void
    {
        $union = $this->union();
        $mission = $this->mission($union);
        $otherMission = $this->mission($union);
        $this->missionAttendee($mission);
        $this->missionAttendee($otherMission);
        $this->unionAttendee($union);

        $this->certificate->update(['audience_mission_ids' => [$mission->id]]);

        $this->assertSame(1, $this->audienceCount());
    }

    public function test_hand_picked_attendees_are_added_and_counted_once(): void
    {
        $union = $this->union();
        $inUnion = $this->unionAttendee($union);
        $outsider = $this->unionAttendee($this->union());
        $this->unionAttendee($this->union());

        $this->certificate->update([
            'audience_union_ids' => [$union->id],
            'audience_attendee_ids' => [$inUnion->id, $outsider->id],
        ]);

        $this->assertSame(2, $this->audienceCount());
    }

    public function test_hand_picked_attendees_alone_make_the_audience_and_must_still_be_fully_registered(): void
    {
        $picked = $this->unionAttendee($this->union());
        $draft = Attendee::factory()->for($this->event)->draft()->create();
        $this->unionAttendee($this->union());

        $this->certificate->update(['audience_attendee_ids' => [$picked->id, $draft->id]]);

        $this->assertSame(1, $this->audienceCount());
    }

    /** A Fully registered Attendee of the event who picked the Breakout. */
    private function picker(Breakout $breakout, bool $overbooked = false): Attendee
    {
        $attendee = Attendee::factory()->for($this->event)->create();
        $attendee->breakouts()->attach($breakout->id, ['overbooked' => $overbooked]);

        return $attendee;
    }

    private function breakout(?Event $event = null): Breakout
    {
        return Breakout::factory()->for($event ?? $this->event)->create();
    }

    public function test_breakout_ids_round_trip_through_the_certificate_resource(): void
    {
        $breakout = $this->breakout();

        $this->putJson($this->url(), ['audience' => ['breakout_ids' => [$breakout->id]]])
            ->assertOk()
            ->assertJsonPath('data.audience.breakout_ids', [$breakout->id]);

        $this->getJson($this->url())->assertJsonPath('data.audience.breakout_ids', [$breakout->id]);
    }

    public function test_certificates_without_breakouts_report_an_empty_list(): void
    {
        $this->getJson($this->url())->assertJsonPath('data.audience.breakout_ids', []);
    }

    public function test_breakout_ids_must_belong_to_the_certificates_event(): void
    {
        $foreign = $this->breakout(Event::factory()->for($this->org)->create());

        $this->putJson($this->url(), ['audience' => ['breakout_ids' => [$foreign->id]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('audience.breakout_ids.0');
    }

    public function test_a_breakout_alone_makes_the_audience_its_pickers(): void
    {
        $breakout = $this->breakout();
        $this->picker($breakout);
        $this->picker($breakout);
        $this->picker($this->breakout());
        Attendee::factory()->for($this->event)->create();

        $this->putJson($this->url(), ['audience' => ['breakout_ids' => [$breakout->id]]])->assertOk();

        $this->assertSame(2, $this->audienceCount());
    }

    public function test_breakout_pickers_are_added_to_a_union_selection_and_counted_once(): void
    {
        $union = $this->union();
        $breakout = $this->breakout();
        $unionPicker = $this->picker($breakout);
        $unionPicker->forceFill(['organization_level' => OrganizationLevel::Union, 'union_id' => $union->id])->saveQuietly();
        $this->unionAttendee($union);
        $this->picker($breakout);
        $this->unionAttendee($this->union());

        $this->putJson($this->url(), ['audience' => ['union_ids' => [$union->id], 'breakout_ids' => [$breakout->id]]])->assertOk();

        $this->assertSame(3, $this->audienceCount());
    }

    public function test_an_overbooked_pick_counts_as_a_picker(): void
    {
        $breakout = $this->breakout();
        $this->picker($breakout, overbooked: true);
        $this->picker($breakout);

        $this->putJson($this->url(), ['audience' => ['breakout_ids' => [$breakout->id]]])->assertOk();

        $this->assertSame(2, $this->audienceCount());
    }

    public function test_a_picker_who_is_not_fully_registered_is_excluded(): void
    {
        $breakout = $this->breakout();
        $this->picker($breakout);
        $draft = Attendee::factory()->draft()->for($this->event)->create();
        $draft->breakouts()->attach($breakout->id);

        $this->putJson($this->url(), ['audience' => ['breakout_ids' => [$breakout->id]]])->assertOk();

        $this->assertSame(1, $this->audienceCount());
    }

    public function test_clearing_every_list_again_means_everyone(): void
    {
        $breakout = $this->breakout();
        $this->picker($breakout);
        Attendee::factory()->for($this->event)->create();
        $this->putJson($this->url(), ['audience' => ['breakout_ids' => [$breakout->id]]])->assertOk();
        $this->assertSame(1, $this->audienceCount());

        $this->putJson($this->url(), ['audience' => ['breakout_ids' => []]])->assertOk();

        $this->assertSame(2, $this->audienceCount());
    }

    public function test_audience_count_is_hidden_from_another_organizations_admin(): void
    {
        $this->actingAs(User::factory()->orgAdmin()->for(Organization::factory()->create())->create(), 'sanctum');

        $this->getJson($this->url('/audience-count'))->assertNotFound();
    }
}
