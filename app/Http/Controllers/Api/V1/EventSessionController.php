<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEventSessionRequest;
use App\Http\Requests\UpdateEventSessionRequest;
use App\Http\Resources\EventSessionResource;
use App\Models\Event;
use App\Models\EventSession;

class EventSessionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Event $event)
    {
        $this->authorize('view', $event);

        return EventSessionResource::collection(
            $event->sessions()->orderBy('session_date')->orderBy('start_time')->get()
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreEventSessionRequest $request, Event $event)
    {
        $session = $event->sessions()->create($request->validated());

        return EventSessionResource::make($session)->response()->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Event $event, EventSession $session)
    {
        $this->authorize('view', $session);

        return EventSessionResource::make($session);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateEventSessionRequest $request, Event $event, EventSession $session)
    {
        $session->update($request->validated());

        return EventSessionResource::make($session);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Event $event, EventSession $session)
    {
        $this->authorize('delete', $session);

        $session->delete();

        return response()->noContent();
    }
}

