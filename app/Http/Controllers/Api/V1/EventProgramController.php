<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEventProgramRequest;
use App\Http\Resources\EventProgramResource;
use App\Models\AuditLog;
use App\Models\Event;
use Illuminate\Support\Facades\Storage;

class EventProgramController extends Controller
{
    /**
     * Store a newly created program for an event.
     */
    public function store(StoreEventProgramRequest $request, Event $event)
    {
        if ($event->program()->exists()) {
            return response()->json(['message' => 'A program already exists for this event.'], 409);
        }

        $program = $event->program()->create($request->validated());

        AuditLog::record('event_program.created', $program);

        return EventProgramResource::make($program)->response()->setStatusCode(201);
    }

    /**
     * Display the program for an event.
     */
    public function show(Event $event)
    {
        $this->authorize('view', $event);

        $program = $event->program;

        if (! $program) {
            return response()->json(['message' => 'No program found for this event.'], 404);
        }

        return EventProgramResource::make($program->load('items', 'days.sections.items'));
    }

    /**
     * Remove a program from storage.
     */
    public function destroy(Event $event)
    {
        $program = $event->program;

        if (! $program) {
            return response()->json(['message' => 'No program found for this event.'], 404);
        }

        $this->authorize('delete', $program);

        $program->items->each(function ($item) {
            if ($item->photo_paths) {
                foreach ($item->photo_paths as $pathMap) {
                    if (isset($pathMap['original'])) {
                        Storage::disk('public')->delete($pathMap['original']);
                    }
                }
            }
        });

        $program->delete();

        AuditLog::record('event_program.deleted', $program);

        return response()->noContent();
    }
}
