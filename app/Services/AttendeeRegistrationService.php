<?php

namespace App\Services;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;

/**
 * Creates the per-event Attendee that is a person's registration in one event.
 */
class AttendeeRegistrationService
{
    /**
     * Identity fields copied from the person's earlier Attendee. Past events never change when a later profile is edited.
     */
    private const PREFILL_COLUMNS = [
        'organization_level', 'union_id', 'mission_id', 'church_id',
        'first_name', 'middle_name', 'last_name', 'mobile_no', 'email_address', 'remarks', 'user_id',
    ];

    /**
     * Register the person behind $source in $event, prefilled from $source.
     */
    public function registerFrom(Attendee $source, Event $event, ?User $registeredBy, bool $confirmed = true): Attendee
    {
        $prefill = $source->only(self::PREFILL_COLUMNS);

        // Territory ids belong to the source's organization; never carry them into another organization's event.
        if ($source->organization_id !== $event->organization_id) {
            $prefill = [...$prefill, 'organization_level' => null, 'union_id' => null, 'mission_id' => null, 'church_id' => null];
        }

        // The email of a linked attendee mirrors the user account.
        if ($source->user_id !== null) {
            $prefill['email_address'] = $source->user?->email ?? $prefill['email_address'];
        }

        $attendee = Attendee::create([
            ...$prefill,
            'event_id' => $event->id,
            'registered_by' => $registeredBy?->id,
            'registration_confirmed_at' => $confirmed && ($registeredBy === null || $registeredBy->id !== $source->user_id) ? now() : null,
            'created_by' => $registeredBy?->id ?? $source->created_by,
        ]);

        $this->copyPhotos($source, $attendee);

        return $attendee;
    }

    /**
     * Register the user in $event, prefilled from their latest Attendee, else from the User.
     * Returns the Attendee and whether it was created now.
     *
     * @return array{0: Attendee, 1: bool}
     */
    public function registerUser(User $user, Event $event): array
    {
        $existing = $this->userAttendeeIn($user, $event);

        if ($existing !== null) {
            return [$existing, false];
        }

        try {
            $latest = Attendee::withoutGlobalScopes()->where('user_id', $user->id)->latest('id')->first();

            if ($latest !== null) {
                return [$this->registerFrom($latest, $event, $user), true];
            }

            return [Attendee::create([
                'event_id' => $event->id,
                'first_name' => $user->first_name ?? Str::before($user->name, ' '),
                'middle_name' => $user->middle_name,
                'last_name' => $user->last_name ?? Str::after($user->name, ' '),
                'email_address' => $user->email,
                'user_id' => $user->id,
                'registered_by' => $user->id,
                'created_by' => $user->id,
            ]), true];
        } catch (UniqueConstraintViolationException) {
            // A concurrent request registered the user first.
            return [$this->userAttendeeIn($user, $event) ?? throw new LogicException('Registration conflict without an Attendee.'), false];
        }
    }

    public function userAttendeeIn(User $user, Event $event): ?Attendee
    {
        return Attendee::withoutGlobalScopes()->where('event_id', $event->id)->where('user_id', $user->id)->first();
    }

    /**
     * Each Attendee owns its photo files, so deleting one never breaks another.
     */
    private function copyPhotos(Attendee $source, Attendee $attendee): void
    {
        if (empty($source->photo_paths)) {
            return;
        }

        $disk = Storage::disk('local');
        $paths = [];

        foreach ($source->photo_paths as $key => $path) {
            if (! is_string($path) || ! $disk->exists($path)) {
                continue;
            }

            $target = "attendees/{$attendee->id}/".basename($path);
            $disk->copy($path, $target);
            $paths[$key] = $target;
        }

        if ($paths !== []) {
            $attendee->update(['photo_paths' => $paths]);
        }
    }
}
