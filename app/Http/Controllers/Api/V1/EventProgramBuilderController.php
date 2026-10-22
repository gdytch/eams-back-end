<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveEventProgramNodeRequest;
use App\Http\Resources\EventProgramDayResource;
use App\Http\Resources\EventProgramItemResource;
use App\Http\Resources\EventProgramResource;
use App\Http\Resources\EventProgramSectionResource;
use App\Models\AuditLog;
use App\Models\Breakout;
use App\Models\Event;
use App\Models\EventProgram;
use App\Services\EventProgramService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

class EventProgramBuilderController extends Controller
{
    public function __construct(private EventProgramService $service) {}

    public function metadata(SaveEventProgramNodeRequest $request, Event $event): EventProgramResource
    {
        $program = $event->program()->firstOrFail();
        $data = $request->validated();
        if ($data['is_public'] && empty($data['public_slug'])) {
            $data['public_slug'] = EventProgram::generateUniquePublicSlug(
                $data['title'] ?: $event->name,
                $program->id,
            );
        }
        $program->update($data);
        AuditLog::record('event_program.updated', $program);

        return EventProgramResource::make($program);
    }

    public function store(SaveEventProgramNodeRequest $request, Event $event, ?Breakout $breakout, string $kind): JsonResponse
    {
        $node = $this->service->save($this->program($event, $breakout), $kind, $request->validated());

        return $this->resource($kind, $node)->response()->setStatusCode(201);
    }

    public function update(SaveEventProgramNodeRequest $request, Event $event, ?Breakout $breakout, string $kind, int $node): JsonResource
    {
        return $this->resource($kind, $this->service->save($this->program($event, $breakout), $kind, $request->validated(), $node));
    }

    public function destroy(SaveEventProgramNodeRequest $request, Event $event, ?Breakout $breakout, string $kind, int $node): Response
    {
        $this->service->destroy($this->program($event, $breakout), $kind, $node);

        return response()->noContent();
    }

    public function duplicate(SaveEventProgramNodeRequest $request, Event $event, ?Breakout $breakout, string $kind, int $node): JsonResponse
    {
        return $this->resource($kind, $this->service->duplicate($this->program($event, $breakout), $kind, $node))->response()->setStatusCode(201);
    }

    public function reorder(SaveEventProgramNodeRequest $request, Event $event, ?Breakout $breakout, string $kind): Response
    {
        $data = $request->validated();
        $this->service->reorder($this->program($event, $breakout), $kind, $data['ids'], $data['parent_id'] ?? null);

        return response()->noContent();
    }

    /** The breakout's program on breakout routes, else the event program. */
    private function program(Event $event, ?Breakout $breakout): EventProgram
    {
        // On event routes the container injects an empty Breakout.
        return ($breakout?->exists ? $breakout->program() : $event->program())->firstOrFail();
    }

    private function resource(string $kind, Model $node): JsonResource
    {
        return match ($kind) {
            'days' => EventProgramDayResource::make($node),
            'sections' => EventProgramSectionResource::make($node),
            'parts' => EventProgramItemResource::make($node),
        };
    }
}
