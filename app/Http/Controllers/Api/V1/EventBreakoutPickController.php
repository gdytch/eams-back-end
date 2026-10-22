<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateEventBreakoutPicksRequest;
use App\Http\Resources\EventResource;
use App\Jobs\RecomputeEventCompletenessJob;
use App\Models\AuditLog;
use App\Models\Event;
use App\Services\BreakoutPickRules;
use App\Services\RegistrationCompleteness;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventBreakoutPickController extends Controller
{
    public function update(UpdateEventBreakoutPicksRequest $request, Event $event, RegistrationCompleteness $completeness)
    {
        $mode = $request->validated('mode');
        [$min, $max] = match ($mode) {
            null => [null, null],
            'one' => [1, 1],
            default => [$request->validated('min') ?? 1, $request->validated('max')],
        };

        $changed = DB::transaction(function () use ($request, $event, $completeness, $mode, $min, $max) {
            // Serialize with breakout edits and other setting changes.
            Event::withoutGlobalScopes()->whereKey($event->id)->lockForUpdate()->first();

            $this->validateSetting($event, $mode, $min, $max);

            $event->forceFill(['pick_mode' => $mode, 'pick_min' => $min, 'pick_max' => $max]);
            if (! $event->isDirty()) {
                return false;
            }
            $event->save();

            // Dry run on the saved setting; roll back and ask for confirmation when it would hurt.
            $count = $completeness->countBecomingIncomplete($event);
            if ($count > 0 && ! $request->boolean('confirm')) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Saving this setting makes registered Attendees incomplete. Send confirm to continue.',
                    'requires_confirmation' => true,
                    'would_become_incomplete' => $count,
                ], 409));
            }

            AuditLog::record('event.breakout_picks_updated', $event, ['mode' => $mode, 'min' => $min, 'max' => $max]);

            return true;
        });

        if ($changed) {
            RecomputeEventCompletenessJob::dispatch($event);
        }

        return EventResource::make($event);
    }

    private function validateSetting(Event $event, ?string $mode, ?int $min, ?int $max): void
    {
        if ($mode === null) {
            if ($event->breakouts()->whereHas('picks')->exists()) {
                $this->fail('Breakouts cannot be disabled while Attendees hold picks.');
            }

            return;
        }

        $breakouts = $event->breakouts()->get();
        if ($breakouts->isEmpty()) {
            $this->fail('Add at least one breakout before enabling picks.');
        }
        if ($min > BreakoutPickRules::largestNonOverlapping($breakouts)) {
            $this->fail('The minimum is more than the largest set of breakouts that do not overlap.');
        }
        if ($max !== null && $max < $min) {
            throw ValidationException::withMessages(['max' => 'The maximum cannot be below the minimum.']);
        }
        // Picks under the minimum only make an Attendee incomplete (confirmed via the dry run); they do not block.
        if (BreakoutPickRules::anyAttendeePicksBreak($event, 0, $max)) {
            $this->fail("Some Attendees' current picks would break this setting.");
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['mode' => $message]);
    }
}
