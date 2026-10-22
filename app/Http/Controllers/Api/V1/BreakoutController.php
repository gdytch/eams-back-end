<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveBreakoutRequest;
use App\Http\Resources\BreakoutResource;
use App\Models\AuditLog;
use App\Models\Breakout;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BreakoutController extends Controller
{
    public function index(Request $request, Event $event)
    {
        $this->authorize('viewStaffData', $event);
        $request->validate(['overbooked' => ['nullable', 'boolean']]);

        return BreakoutResource::collection(
            $event->breakouts()
                ->withCount('picks')
                ->with('speakers')
                ->when($request->boolean('overbooked'), fn ($query) => $query->overbooked())
                ->orderBy('date')->orderBy('start_time')->orderBy('id')
                ->get()
        );
    }

    public function store(SaveBreakoutRequest $request, Event $event)
    {
        $breakout = DB::transaction(function () use ($request, $event) {
            $breakout = $event->breakouts()->create([...$request->safe()->except('speaker_ids'), 'organization_id' => $event->organization_id]);
            $this->syncSpeakers($breakout, $request->validated('speaker_ids'));
            AuditLog::record('breakout.created', $breakout);

            return $breakout;
        });

        return BreakoutResource::make($this->loaded($breakout))->response()->setStatusCode(201);
    }

    public function show(Event $event, Breakout $breakout)
    {
        $this->authorize('view', $breakout);

        return BreakoutResource::make($this->loaded($breakout)->load('program.days.sections.items'));
    }

    public function update(SaveBreakoutRequest $request, Event $event, Breakout $breakout)
    {
        DB::transaction(function () use ($request, $breakout) {
            $breakout->update($request->safe()->except('speaker_ids'));
            $this->syncSpeakers($breakout, $request->validated('speaker_ids'));
            // The program's single day always follows the breakout date.
            if ($breakout->program) {
                $breakout->program->days()->update(['date' => $breakout->date]);
                $breakout->program->items()->update(['date' => $breakout->date]);
            }
            AuditLog::record('breakout.updated', $breakout);
        });

        return BreakoutResource::make($this->loaded($breakout));
    }

    public function destroy(Event $event, Breakout $breakout)
    {
        $this->authorize('delete', $breakout);

        // Lock the row so a pick cannot arrive between the check and the cascade delete.
        $deleted = DB::transaction(function () use ($breakout) {
            Breakout::whereKey($breakout->id)->lockForUpdate()->first();
            if ($breakout->picks()->exists()) {
                return false;
            }
            $breakout->delete();

            return true;
        });

        if (! $deleted) {
            return response()->json(['message' => 'A breakout with picks cannot be deleted.'], 422);
        }

        AuditLog::record('breakout.deleted', $breakout);

        return response()->noContent();
    }

    /** @param  array<int, int>  $speakerIds */
    private function syncSpeakers(Breakout $breakout, array $speakerIds): void
    {
        $breakout->speakers()->sync(collect($speakerIds)->values()->mapWithKeys(
            fn ($id, $order) => [$id => ['sort_order' => $order]]
        )->all());
    }

    private function loaded(Breakout $breakout): Breakout
    {
        return $breakout->load('speakers')->loadCount('picks');
    }
}
