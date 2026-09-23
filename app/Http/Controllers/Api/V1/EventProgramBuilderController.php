<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveEventProgramNodeRequest;
use App\Http\Resources\EventProgramDayResource;
use App\Http\Resources\EventProgramItemResource;
use App\Http\Resources\EventProgramResource;
use App\Http\Resources\EventProgramSectionResource;
use App\Models\AuditLog;
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

    public function store(SaveEventProgramNodeRequest $request, Event $event, string $kind): JsonResponse
    {
        $node = $this->service->save($event->program()->firstOrFail(), $kind, $request->validated());

        return $this->resource($kind, $node)->response()->setStatusCode(201);
    }

    public function update(SaveEventProgramNodeRequest $request, Event $event, string $kind, int $node): JsonResource
    {
        return $this->resource($kind, $this->service->save($event->program()->firstOrFail(), $kind, $request->validated(), $node));
    }

    public function destroy(SaveEventProgramNodeRequest $request, Event $event, string $kind, int $node): Response
    {
        $this->service->destroy($event->program()->firstOrFail(), $kind, $node);

        return response()->noContent();
    }

    public function duplicate(SaveEventProgramNodeRequest $request, Event $event, string $kind, int $node): JsonResponse
    {
        return $this->resource($kind, $this->service->duplicate($event->program()->firstOrFail(), $kind, $node))->response()->setStatusCode(201);
    }

    public function reorder(SaveEventProgramNodeRequest $request, Event $event, string $kind): Response
    {
        $data = $request->validated();
        $this->service->reorder($event->program()->firstOrFail(), $kind, $data['ids'], $data['parent_id'] ?? null);

        return response()->noContent();
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
