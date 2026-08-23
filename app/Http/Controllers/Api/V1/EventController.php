<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Http\Resources\EventResource;
use App\Models\Event;
use Illuminate\Http\Request;

class EventController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Event::class);

        $query = Event::query();

        if (! $request->user()->isSuperAdmin() && ! $request->user()->isOrgAdmin()) {
            $userId = $request->user()->id;

            $query->where(function ($q) use ($userId) {
                $q->whereHas('checkers', fn ($q) => $q->whereKey($userId))
                    ->orWhereDoesntHave('checkers');
            });
        }

        return EventResource::collection($query->paginate());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreEventRequest $request)
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()->id;

        $event = Event::create($data);

        return EventResource::make($event)->response()->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Event $event)
    {
        $this->authorize('view', $event);

        return EventResource::make($event);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateEventRequest $request, Event $event)
    {
        $event->update($request->validated());

        return EventResource::make($event);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Event $event)
    {
        $this->authorize('delete', $event);

        $event->delete();

        return response()->noContent();
    }
}

