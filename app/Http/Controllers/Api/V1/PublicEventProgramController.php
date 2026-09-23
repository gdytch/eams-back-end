<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicEventProgramResource;
use App\Models\EventProgram;

class PublicEventProgramController extends Controller
{
    public function show(string $publicSlug): PublicEventProgramResource
    {
        $program = EventProgram::query()
            ->where('public_slug', $publicSlug)
            ->where('is_public', true)
            ->with(['event.organization', 'days.sections.items.speaker.programItems'])
            ->first();
        if (! $program) {
            abort(404);
        }

        abort_unless($program->event?->status === EventStatus::Published, 404);

        return PublicEventProgramResource::make($program);
    }
}
