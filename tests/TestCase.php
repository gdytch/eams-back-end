<?php

namespace Tests;

use App\Models\Attendee;
use App\Models\Event;
use App\Services\AttendeeRegistrationService;
use Database\Factories\EventFactory;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Factories write photos using IDs that can also exist in the development database.
        Storage::fake('local');
        Storage::fake('public');
    }

    /**
     * Register an existing attendee in an event. An attendee that has no event yet becomes that event's
     * registration; one that already has an event gets a copy for the new event.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function registerInEvent(Event|EventFactory $event, Attendee $attendee, array $attributes = []): Attendee
    {
        $event = $event instanceof EventFactory ? $event->create() : $event;

        if ($attendee->event_id === null) {
            $attendee->forceFill([...$attributes, 'event_id' => $event->id])->save();

            return $attendee->refresh();
        }

        $registration = app(AttendeeRegistrationService::class)->registerFrom($attendee, $event, null);

        if ($attributes !== []) {
            $registration->forceFill($attributes)->save();
        }

        return $registration;
    }
}
