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
        $event = $this->resolvePublishedEvent($eventInviteToken);

        return PublicEventResource::make($event);
    }

    /**
     * Resolve event by invite token, ensuring it's published (404 otherwise).
     */
    private function resolvePublishedEvent(string $inviteToken): Event
    {
        $event = Event::withoutGlobalScopes()
            ->where('invite_token', $inviteToken)
            ->with(['organization', 'sessions', 'program.items', 'speakers.programItems'])
            ->firstOrFail();

        abort_unless($event->status === EventStatus::Published, 404);

        return $event;
    }
}
