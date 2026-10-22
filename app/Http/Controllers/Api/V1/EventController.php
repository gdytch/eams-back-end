<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Http\Requests\UploadEventBannerRequest;
use App\Http\Requests\UploadEventIdCardBackgroundRequest;
use App\Http\Resources\EventResource;
use App\Jobs\GenerateAttendeeIdCardJob;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\User;
use App\Services\ImageUploadService;
use App\Support\ImageInput;
use Illuminate\Database\Eloquent\Builder;
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

        $query = $this->eventsVisibleTo($request->user());
        $request->boolean('archived') ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');

        return EventResource::collection($query->paginate($request->input('per_page', 10)));
    }

    private function eventsVisibleTo(User $user): Builder
    {
        return match (true) {
            $user->isOrgAdmin() => Event::query()->where('organization_id', $user->organization_id),
            $user->isChecker() => Event::query()->where('organization_id', $user->organization_id)->whereNull('archived_at')
                ->when($user->accessibleEvents()->exists(), fn ($query) => $query->whereIn('id', $user->accessibleEvents()->select('events.id'))),
            $user->isAttendee() => Event::query()->where('status', 'published'),
            default => Event::query(),
        };
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

    public function archive(Event $event)
    {
        $this->authorize('archive', $event);

        $event->forceFill(['archived_at' => now(), 'archived_by' => auth()->id()])->save();
        AuditLog::record('event.archived', $event);

        return EventResource::make($event);
    }

    public function unarchive(Event $event)
    {
        $this->authorize('archive', $event);

        $event->forceFill(['archived_at' => null, 'archived_by' => null])->save();
        AuditLog::record('event.unarchived', $event);

        return EventResource::make($event);
    }

    /**
     * Upload (or replace) the event's attendee-ID-card background image.
     */
    public function uploadIdCardBackground(UploadEventIdCardBackgroundRequest $request, Event $event)
    {
        if ($event->id_card_background_path) {
            Storage::disk('public')->delete($event->id_card_background_path);
        }

        $upload = $request->file('background');
        if ($upload !== null) {
            $path = $upload->store("events/{$event->id}", 'public');
        } else {
            $binary = ImageInput::toBinary((string) $request->input('background'));
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_buffer($finfo, $binary);
            finfo_close($finfo);
            $extension = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => throw new \InvalidArgumentException('Unsupported background image type.'),
            };
            $path = "events/{$event->id}/background-".bin2hex(random_bytes(8)).".{$extension}";
            Storage::disk('public')->put($path, $binary);
        }

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
        $event->attendees()->update([
            'id_card_path' => null,
            'id_card_images_path' => null,
            'id_card_generated_at' => null,
        ]);

        $event->attendees()->get()->each(function (Attendee $attendee) {
            GenerateAttendeeIdCardJob::dispatch($attendee);
        });
    }
}
