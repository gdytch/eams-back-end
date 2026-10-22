<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicEventResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;

class PublicEventController extends Controller
{
    /**
     * Display event landing page data by invite token (public, no auth required).
     */
    public function show(string $eventInviteToken): JsonResponse|PublicEventResource
    {
        $event = $this->resolveEventByInviteToken($eventInviteToken);

        // Archived events show the closed state whatever their status; drafts stay hidden.
        abort_unless(
            $event->status === EventStatus::Published || ($event->isArchived() && $event->status !== EventStatus::Draft),
            404
        );

        return PublicEventResource::make($event);
    }

    /**
     * Resolve event by invite token, ensuring it's published (404 otherwise).
     */
    private function resolvePublishedEvent(string $inviteToken): Event
    {
        $event = $this->resolveEventByInviteToken($inviteToken);

        abort_unless($event->status === EventStatus::Published, 404);

        if ($event->program?->is_public) {
            $event->load(['breakouts' => fn ($query) => $query->withoutGlobalScopes()->orderBy('date')->orderBy('start_time')]);
            if ($event->speakers_public) {
                $event->breakouts->load('speakers');
            }
        }

        return $event;
    }

    /**
     * Resolve event by invite token in any status (404 when the token is unknown).
     */
    private function resolveEventByInviteToken(string $inviteToken): Event
    {
        return Event::withoutGlobalScopes()
            ->where('invite_token', $inviteToken)
            ->with(['organization', 'sessions', 'program.items', 'speakers.programItems'])
            ->firstOrFail();
    }
}
