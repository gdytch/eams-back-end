<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Http\Requests\UploadEventBannerRequest;
use App\Http\Requests\UploadEventIdCardBackgroundRequest;
use App\Http\Resources\EventResource;
use App\Jobs\GenerateAttendeeIdCardJob;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
        if ($request->user()->isAttendee()) {
            $query->where('status', 'published');
        }

        return EventResource::collection($query->paginate($request->input('per_page', 10)));
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
        $data = $request->validated();
        $oldFontColor = $event->id_card_font_color;

        $event->update($data);

        // Regenerate ID cards if font color changed
        if (array_key_exists('id_card_font_color', $data) && $data['id_card_font_color'] !== $oldFontColor) {
            $this->regenerateIdCardsFor($event);
        }

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

    /**
     * Upload (or replace) the event's attendee-ID-card background image.
     */
    public function uploadIdCardBackground(UploadEventIdCardBackgroundRequest $request, Event $event)
    {
        if ($event->id_card_background_path) {
            Storage::disk('public')->delete($event->id_card_background_path);
        }

        $path = $request->file('background')->store("events/{$event->id}", 'public');

        $event->update(['id_card_background_path' => $path]);

        $this->regenerateIdCardsFor($event);

        AuditLog::record('event.id_card_background_updated', $event);

        return EventResource::make($event);
    }

    /**
     * Remove the event's attendee-ID-card background image.
     */
    public function removeIdCardBackground(Event $event)
    {
        $this->authorize('update', $event);

        if ($event->id_card_background_path) {
            Storage::disk('public')->delete($event->id_card_background_path);
        }

        $event->update(['id_card_background_path' => null]);

        $this->regenerateIdCardsFor($event);

        AuditLog::record('event.id_card_background_removed', $event);

        return EventResource::make($event);
    }

    /**
     * Upload (or replace) the event's banner image.
     */
    public function uploadBanner(UploadEventBannerRequest $request, Event $event)
    {
        $service = new ImageUploadService;
        $input = $request->file('banner') ?? $request->input('banner');

        $paths = $service->process(
            $input,
            preset: 'event_banner',
            directory: "events/{$event->id}",
            prefix: 'banner',
        );

        $event->update(['banner_paths' => $paths]);

        AuditLog::record('event.banner_updated', $event);

        return EventResource::make($event);
    }

    /**
     * Remove the event's banner image.
     */
    public function removeBanner(Event $event)
    {
        $this->authorize('update', $event);

        if ($event->banner_paths) {
            foreach ($event->banner_paths as $path) {
                Storage::disk('public')->delete($path);
            }
        }

        $event->update(['banner_paths' => null]);

        AuditLog::record('event.banner_removed', $event);

        return EventResource::make($event);
    }

    /**
     * Clear and regenerate ID cards for all registrations of the event.
     */
    private function regenerateIdCardsFor(Event $event): void
    {
        $event->registrations()->update([
            'id_card_path' => null,
            'id_card_images_path' => null,
            'id_card_generated_at' => null,
        ]);

        $event->registrations->each(function (EventRegistration $registration) {
            GenerateAttendeeIdCardJob::dispatch($registration);
        });
    }
}
