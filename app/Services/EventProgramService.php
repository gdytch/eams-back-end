<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\EventProgram;
use App\Models\EventProgramDay;
use App\Models\EventProgramItem;
use App\Models\EventProgramSection;
use App\Models\Speaker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class EventProgramService
{
    public function query(EventProgram $program, string $kind): Builder
    {
        return match ($kind) {
            'days' => EventProgramDay::query()->where('event_program_id', $program->id),
            'sections' => EventProgramSection::query()->whereHas('day', fn ($query) => $query->where('event_program_id', $program->id)),
            'parts' => $program->items()->getQuery()->whereNotNull('event_program_section_id'),
        };
    }

    public function siblings(EventProgram $program, string $kind, ?int $parentId): Builder
    {
        $query = $this->query($program, $kind);
        if ($kind !== 'days') {
            $this->query($program, $kind === 'sections' ? 'days' : 'sections')->findOrFail($parentId);
            $query->where($kind === 'sections' ? 'event_program_day_id' : 'event_program_section_id', $parentId);
        }

        return $query;
    }

    public function save(EventProgram $program, string $kind, array $data, ?int $id = null): Model
    {
        return DB::transaction(function () use ($program, $kind, $data, $id) {
            EventProgram::whereKey($program->id)->lockForUpdate()->firstOrFail();
            $parentId = $data['parent_id'] ?? null;
            unset($data['parent_id']);
            if ($kind === 'parts') {
                foreach (['title' => 'part_title', 'designation' => 'participant_description', 'details' => 'part_description'] as $input => $column) {
                    if (array_key_exists($input, $data)) {
                        $data[$column] = $data[$input];
                        unset($data[$input]);
                    }
                }
                if (array_key_exists('speaker_id', $data) && $data['speaker_id']) {
                    $speaker = Speaker::query()
                        ->whereBelongsTo($program->event)
                        ->findOrFail($data['speaker_id']);
                    $data['participant_name'] = $speaker->name;
                    $data['participant_description'] = $speaker->designation;
                }
            }
            if ($id !== null) {
                $node = $this->query($program, $kind)->findOrFail($id);
                $node->update($data);
            } else {
                $siblings = $this->siblings($program, $kind, $parentId);
                $column = $kind === 'parts' ? 'order' : 'sort_order';
                $data[$column] = ($siblings->max($column) ?? -1) + 1;
                if ($kind === 'days') {
                    $node = $program->days()->create($data);
                } elseif ($kind === 'sections') {
                    $node = $this->query($program, 'days')->findOrFail($parentId)->sections()->create($data);
                } else {
                    $section = $this->query($program, 'sections')->with('day')->findOrFail($parentId);
                    $node = $section->items()->create([...$data, 'event_program_id' => $program->id, 'date' => $section->day->date]);
                }
            }
            if ($kind === 'days') {
                $program->items()->whereHas('section', fn ($query) => $query->where('event_program_day_id', $node->id))->update(['date' => $node->date]);
            }
            AuditLog::record('event_program.'.$kind.'.saved', $node);

            return $this->loadNode($kind, $node);
        });
    }

    public function loadNode(string $kind, Model $node): Model
    {
        return match ($kind) {
            'days' => $node->load('sections.items'),
            'sections' => $node->load('items'),
            'parts' => $node,
        };
    }

    public function reorder(EventProgram $program, string $kind, array $ids, ?int $parentId): void
    {
        DB::transaction(function () use ($program, $kind, $ids, $parentId) {
            EventProgram::whereKey($program->id)->lockForUpdate()->firstOrFail();
            $siblings = $this->siblings($program, $kind, $parentId);
            $actual = $siblings->pluck('id')->all();
            $requested = array_map('intval', $ids);
            sort($actual);
            sort($requested);
            if ($actual !== $requested) {
                throw ValidationException::withMessages(['ids' => 'Include every sibling exactly once. Reload the program and try again.']);
            }
            foreach ($ids as $order => $id) {
                (clone $siblings)->whereKey($id)->update([$kind === 'parts' ? 'order' : 'sort_order' => $order]);
            }
            AuditLog::record('event_program.'.$kind.'.reordered', $program);
        });
    }

    public function duplicate(EventProgram $program, string $kind, int $id): Model
    {
        abort_unless(in_array($kind, ['sections', 'parts'], true), 404);

        return DB::transaction(function () use ($program, $kind, $id) {
            EventProgram::whereKey($program->id)->lockForUpdate()->firstOrFail();
            $node = $this->query($program, $kind)->findOrFail($id);
            $column = $kind === 'parts' ? 'order' : 'sort_order';
            $parent = $kind === 'parts' ? $node->event_program_section_id : $node->event_program_day_id;
            $siblings = $this->siblings($program, $kind, $parent)->orderBy($column)->orderBy('id')->get();
            $copy = $node->replicate(['photo_paths']);
            $copy->save();
            if ($kind === 'sections') {
                foreach ($node->items as $item) {
                    $child = $item->replicate(['photo_paths']);
                    $child->event_program_section_id = $copy->id;
                    $child->save();
                }
            }
            $order = 0;
            foreach ($siblings as $sibling) {
                $sibling->update([$column => $order++]);
                if ($sibling->id === $node->id) {
                    $copy->update([$column => $order++]);
                }
            }
            AuditLog::record('event_program.'.$kind.'.duplicated', $copy);

            return $this->loadNode($kind, $copy);
        });
    }

    public function saveLegacyItem(EventProgram $program, array $data, ?EventProgramItem $item = null): EventProgramItem
    {
        return DB::transaction(function () use ($program, $data, $item) {
            EventProgram::whereKey($program->id)->lockForUpdate()->firstOrFail();
            if (array_key_exists('speaker_id', $data) && $data['speaker_id']) {
                $speaker = Speaker::query()
                    ->whereBelongsTo($program->event)
                    ->findOrFail($data['speaker_id']);
                $data['participant_name'] = $speaker->name;
                $data['participant_description'] = $speaker->designation;
            }
            $date = array_key_exists('date', $data) ? $data['date'] : $item?->date?->toDateString();
            $date ??= $program->event->start_date?->toDateString() ?? $program->created_at->toDateString();
            if (! $item?->event_program_section_id || $item->section->day->date->toDateString() !== $date) {
                $day = $program->days()->firstOrCreate(['date' => $date], ['sort_order' => ($program->days()->max('sort_order') ?? -1) + 1]);
                $section = $day->sections()->firstOrCreate(['title' => 'Program'], ['sort_order' => ($day->sections()->max('sort_order') ?? -1) + 1]);
                $data['event_program_section_id'] = $section->id;
                $data['order'] = ($section->items()->max('order') ?? -1) + 1;
            }
            $data['date'] = $date;
            if ($item) {
                $item->update($data);

                return $item;
            }

            return $program->items()->create($data);
        });
    }

    public function destroy(EventProgram $program, string $kind, int $id): void
    {
        DB::transaction(function () use ($program, $kind, $id) {
            EventProgram::whereKey($program->id)->lockForUpdate()->firstOrFail();
            $node = $this->query($program, $kind)->findOrFail($id);
            $items = match ($kind) {
                'days' => $program->items()->whereHas('section', fn ($query) => $query->where('event_program_day_id', $node->id))->get(),
                'sections' => $node->items,
                'parts' => collect([$node]),
            };
            $paths = $items->flatMap(fn ($item) => collect($item->photo_paths ?? [])->flatMap(fn ($versions) => array_values($versions)))->filter()->unique()->values()->all();
            $node->delete();
            if ($paths !== []) {
                DB::afterCommit(fn () => Storage::disk('public')->delete($paths));
            }
            AuditLog::record('event_program.'.$kind.'.deleted', $node);
        });
    }
}
