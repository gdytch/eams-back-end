<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class AttendeeDashboardResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profile = null;
        if ($this->resource['profile']) {
            $attendee = $this->resource['profile'];
            $profile = [
                'id' => $attendee->id,
                'organization_id' => $attendee->organization_id,
                'union_id' => $attendee->union_id,
                'mission_id' => $attendee->mission_id,
                'first_name' => $attendee->first_name,
                'middle_name' => $attendee->middle_name,
                'last_name' => $attendee->last_name,
                'full_name' => trim("{$attendee->first_name} {$attendee->middle_name} {$attendee->last_name}"),
                'photo_urls' => $attendee->photo_urls,
                'union' => $attendee->union ? [
                    'id' => $attendee->union->id,
                    'name' => $attendee->union->name,
                ] : null,
                'mission' => $attendee->mission ? [
                    'id' => $attendee->mission->id,
                    'name' => $attendee->mission->name,
                ] : null,
                'church' => $attendee->church ? [
                    'id' => $attendee->church->id,
                    'name' => $attendee->church->name,
                ] : null,
                'created_at' => $attendee->created_at,
                'updated_at' => $attendee->updated_at,
            ];
        }

        $user = $this->resource['account'];
        $account = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'organization_id' => $user->organization_id,
            'photo_urls' => $user->photo_urls,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];

        return [
            'profile' => $profile,
            'account' => $account,
            'upcoming_events' => $this->transformEvents($this->resource['upcoming_events'], false),
            'past_events' => $this->transformEvents($this->resource['past_events'], true),
            'next_event' => $this->resource['next_event'] ? $this->formatNextEvent($this->resource['next_event']) : null,
            'latest_event' => $this->resource['latest_event'] ? $this->formatLatestEvent($this->resource['latest_event']) : null,
            'stats' => $this->resource['stats'],
        ];
    }

    /**
     * Transform events array to include all necessary fields.
     */
    private function transformEvents(array $events, bool $includeAttendance): array
    {
        return collect($events)->map(function ($eventData) use ($includeAttendance) {
            $event = $eventData['event'];
            $eventArray = [
                'id' => $event->id,
                'organization_id' => $event->organization_id,
                'name' => $event->name,
                'description' => $event->description,
                'start_date' => $event->start_date?->toDateString(),
                'end_date' => $event->end_date?->toDateString(),
                'venue' => $event->venue,
                'status' => $event->status,
                'requires_check_out' => $event->requires_check_out,
                'check_in_window_minutes' => $event->check_in_window_minutes,
                'id_card_background_url' => $event->id_card_background_path
                    ? Storage::disk('public')->url($event->id_card_background_path)
                    : null,
                'id_card_font_color' => $event->id_card_font_color ?? '#000000',
                'banner_urls' => $event->banner_paths
                    ? collect($event->banner_paths)->mapWithKeys(fn ($path, $key) => [
                        $key => Storage::disk('public')->url($path),
                    ])->toArray()
                    : null,
                'created_at' => $event->created_at,
                'updated_at' => $event->updated_at,
                'invite_token' => $event->invite_token,
            ];

            $formatted = [
                'registration_id' => $eventData['registration_id'],
                'event' => $eventArray,
            ];

            if ($includeAttendance) {
                $formatted['attendance_summary'] = $eventData['attendance_summary'];
            } else {
                $formatted['qr_token'] = $eventData['qr_token'];
                $formatted['id_card_ready'] = $eventData['id_card_ready'];
                $formatted['id_card_url'] = $eventData['id_card_url'];

                if ($eventData['next_session']) {
                    $session = $eventData['next_session'];
                    $formatted['next_session'] = [
                        'id' => $session['id'],
                        'event_id' => $session['event_id'],
                        'name' => $session['name'],
                        'description' => $session['description'],
                        'session_date' => $session['session_date'],
                        'start_time' => $session['start_time'],
                        'end_time' => $session['end_time'],
                        'check_in_opens_at' => $session['check_in_opens_at'],
                    ];
                } else {
                    $formatted['next_session'] = null;
                }
            }

            return $formatted;
        })->toArray();
    }

    /**
     * Format the next upcoming event spotlight (includes days_until_start).
     */
    private function formatNextEvent(array $eventData): array
    {
        $formatted = $this->transformEvents([$eventData], false)[0];
        $formatted['days_until_start'] = today()->diffInDays($eventData['event']->start_date);

        return $formatted;
    }

    /**
     * Format the latest past event spotlight (includes attendance_summary).
     */
    private function formatLatestEvent(array $eventData): array
    {
        return $this->transformEvents([$eventData], true)[0];
    }
}
