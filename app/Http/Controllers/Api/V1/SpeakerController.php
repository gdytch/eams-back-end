<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSpeakerRequest;
use App\Http\Requests\UpdateSpeakerRequest;
use App\Http\Requests\UpdateSpeakerSettingsRequest;
use App\Http\Requests\UploadSpeakerPhotoRequest;
use App\Http\Resources\EventResource;
use App\Http\Resources\SpeakerResource;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Speaker;
use App\Services\ImageUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class SpeakerController extends Controller
{
    public function index(Event $event): AnonymousResourceCollection
    {
        $this->authorize('update', $event);

        return SpeakerResource::collection($event->speakers()->with('programItems')->get());
    }

    public function store(StoreSpeakerRequest $request, Event $event): JsonResponse
    {
        $speaker = $event->speakers()->create(Arr::except($request->validated(), 'photo'));
        try {
            $this->replacePhoto($speaker, $request->file('photo') ?? $request->input('photo'));
        } catch (\Throwable $exception) {
            $speaker->delete();

            throw $exception;
        }
        AuditLog::record('speaker.created', $speaker);

        return SpeakerResource::make($speaker)->response()->setStatusCode(201);
    }

    public function update(UpdateSpeakerRequest $request, Event $event, Speaker $speaker): SpeakerResource
    {
        $speaker->update($request->validated());
        AuditLog::record('speaker.updated', $speaker);

        return SpeakerResource::make($speaker);
    }

    public function uploadPhoto(UploadSpeakerPhotoRequest $request, Event $event, Speaker $speaker): SpeakerResource
    {
        $this->replacePhoto($speaker, $request->file('photo') ?? $request->input('photo'));
        AuditLog::record('speaker.photo_updated', $speaker);

        return SpeakerResource::make($speaker);
    }

    public function destroy(Event $event, Speaker $speaker)
    {
        $this->authorize('update', $event);
        Storage::disk('public')->delete(array_values($speaker->photo_paths ?? []));
        $speaker->delete();
        AuditLog::record('speaker.deleted', $speaker);

        return response()->noContent();
    }

    public function updateSettings(UpdateSpeakerSettingsRequest $request, Event $event): EventResource
    {
        $event->update($request->validated());
        AuditLog::record('event.speakers_settings_updated', $event);

        return EventResource::make($event);
    }

    private function replacePhoto(Speaker $speaker, mixed $photo): void
    {
        $paths = (new ImageUploadService)->process(
            $photo,
            preset: 'profile_photo',
            directory: "speakers/{$speaker->id}",
            prefix: 'photo',
        );

        $speaker->update(['photo_paths' => $paths]);
    }
}
