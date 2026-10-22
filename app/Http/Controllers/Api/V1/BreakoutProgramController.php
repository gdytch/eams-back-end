<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventProgramResource;
use App\Models\AuditLog;
use App\Models\Breakout;
use App\Models\Event;
use Illuminate\Support\Facades\DB;

class BreakoutProgramController extends Controller
{
    /** Create the breakout's program with its single day on the breakout date. */
    public function store(Event $event, Breakout $breakout)
    {
        $this->authorize('update', $breakout);

        if ($breakout->program()->exists()) {
            return response()->json(['message' => 'A program already exists for this breakout.'], 409);
        }

        $program = DB::transaction(function () use ($event, $breakout) {
            $program = $breakout->program()->create(['event_id' => $event->id, 'title' => $breakout->title]);
            $program->days()->create(['date' => $breakout->date]);
            AuditLog::record('event_program.created', $program);

            return $program;
        });

        return EventProgramResource::make($program->load('days.sections.items.speaker'))->response()->setStatusCode(201);
    }

    public function show(Event $event, Breakout $breakout)
    {
        $this->authorize('view', $breakout);

        return EventProgramResource::make($breakout->program()->with('days.sections.items.speaker')->firstOrFail());
    }

    public function destroy(Event $event, Breakout $breakout)
    {
        $this->authorize('update', $breakout);

        $program = $breakout->program()->firstOrFail();
        $program->delete();
        AuditLog::record('event_program.deleted', $program);

        return response()->noContent();
    }
}
