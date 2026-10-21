<?php

namespace App\Policies\Concerns;

use App\Models\Event;
use Illuminate\Auth\Access\Response;

/**
 * Write abilities of event-scoped policies deny archived events with a stable
 * message and the `event_archived` code, after the usual role check passed.
 */
trait DeniesArchivedEvents
{
    protected function denyIfArchived(Event $event): ?Response
    {
        return $event->isArchived() ? $this->archivedDenial() : null;
    }

    protected function archivedDenial(): Response
    {
        return Response::deny('This event is archived.', 'event_archived');
    }

    /**
     * Keep the generic denial for unauthorized users; deny archived events for the rest.
     */
    protected function allowWrite(bool $allowed, Event $event): Response|bool
    {
        if (! $allowed) {
            return false;
        }

        return $this->denyIfArchived($event) ?? true;
    }
}
