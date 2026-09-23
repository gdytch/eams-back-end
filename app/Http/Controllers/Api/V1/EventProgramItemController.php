<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RemoveEventProgramItemPhotoRequest;
use App\Http\Requests\ReorderEventProgramItemsRequest;
use App\Http\Requests\StoreEventProgramItemRequest;
use App\Http\Requests\UpdateEventProgramItemRequest;
use App\Http\Requests\UploadEventProgramItemPhotoRequest;
use App\Http\Resources\EventProgramItemResource;
use App\Models\AuditLog;
use App\Models\Event;
use App\Services\EventProgramService;
use App\Services\ImageUploadService;
use Illuminate\Support\Facades\Storage;

class EventProgramItemController extends Controller
{
    /**
     * Display all items for a program.
     */
    public function index(Event $event)
    {
        $this->authorize('view', $event);

        $program = $event->program;

        if (! $program) {
            return response()->json(['message' => 'No program found for this event.'], 404);
        }

        return EventProgramItemResource::collection(
            $program->items()->orderBy('date')->orderBy('start_time')->get()
        );
    }

    /**
     * Store a newly created item.
     */
    public function store(StoreEventProgramItemRequest $request, Event $event)
    {
        $program = $event->program;

        if (! $program) {
            return response()->json(['message' => 'No program found for this event.'], 404);
        }

        $item = app(EventProgramService::class)->saveLegacyItem($program, $request->validated());

        AuditLog::record('event_program_item.created', $item);

        return EventProgramItemResource::make($item)->response()->setStatusCode(201);
    }

    /**
     * Display the specified item.
     */
    public function show(Event $event, $itemId)
    {
        $this->authorize('view', $event);

        $program = $event->program;

        if (! $program) {
            abort(404);
        }

        $item = $program->items()->findOrFail($itemId);

        return EventProgramItemResource::make($item);
    }

    /**
     * Update the specified item.
     */
    public function update(UpdateEventProgramItemRequest $request, Event $event, $itemId)
    {
        $program = $event->program;

        if (! $program) {
            abort(404);
        }

        $item = $program->items()->findOrFail($itemId);

        app(EventProgramService::class)->saveLegacyItem($program, $request->validated(), $item);

        AuditLog::record('event_program_item.updated', $item);

        return EventProgramItemResource::make($item);
    }

    /**
     * Remove the specified item.
     */
    public function destroy(Event $event, $itemId)
    {
        $program = $event->program;

        if (! $program) {
            abort(404);
        }

        $item = $program->items()->findOrFail($itemId);

        $this->authorize('delete', $program);

        if ($item->photo_paths) {
            foreach ($item->photo_paths as $pathMap) {
                foreach ($pathMap as $path) {
                    Storage::disk('public')->delete($path);
                }
            }
        }

        $item->delete();

        AuditLog::record('event_program_item.deleted', $item);

        return response()->noContent();
    }

    /**
     * Reorder multiple program items.
     */
    public function reorder(ReorderEventProgramItemsRequest $request, Event $event)
    {
        $program = $event->program;

        if (! $program) {
            return response()->json(['message' => 'No program found for this event.'], 404);
        }

        $itemsData = $request->validated()['items'];

        foreach ($itemsData as $itemData) {
            $item = $program->items()->findOrFail($itemData['id']);
            $item->update(['order' => $itemData['order']]);
            AuditLog::record('event_program_item.reordered', $item);
        }

        $items = $program->items()->orderBy('order')->get();

        return EventProgramItemResource::collection($items)->response()->setStatusCode(200);
    }

    /**
     * Upload (or add) a photo to a program item.
     */
    public function uploadPhoto(UploadEventProgramItemPhotoRequest $request, Event $event, $itemId)
    {
        $program = $event->program;

        if (! $program) {
            abort(404);
        }

        $item = $program->items()->findOrFail($itemId);

        $service = new ImageUploadService;
        $input = $request->file('photo') ?? $request->input('photo');

        $pathMap = $service->process(
            $input,
            preset: 'profile_photo',
            directory: "event_programs/{$program->id}/items/{$item->id}",
            prefix: 'photo',
        );

        $photoPaths = $item->photo_paths ?? [];
        $photoPaths[] = $pathMap;

        $item->update(['photo_paths' => $photoPaths]);

        AuditLog::record('event_program_item.photo_added', $item);

        return EventProgramItemResource::make($item);
    }

    /**
     * Remove a photo from a program item by index.
     */
    public function removePhoto(RemoveEventProgramItemPhotoRequest $request, Event $event, $itemId)
    {
        $program = $event->program;

        if (! $program) {
            abort(404);
        }

        $item = $program->items()->findOrFail($itemId);

        $this->authorize('delete', $program);

        $index = $request->input('index');
        $photoPaths = $item->photo_paths ?? [];

        if (! isset($photoPaths[$index])) {
            return response()->json(['message' => 'Photo index not found.'], 422);
        }

        $pathMap = $photoPaths[$index];

        foreach ($pathMap as $path) {
            Storage::disk('public')->delete($path);
        }

        array_splice($photoPaths, $index, 1);

        $item->update(['photo_paths' => $photoPaths ?: null]);

        AuditLog::record('event_program_item.photo_removed', $item);

        return EventProgramItemResource::make($item);
    }
}
