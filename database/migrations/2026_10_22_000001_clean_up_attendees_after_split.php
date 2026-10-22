<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Release 1: data clean-up after split_attendees_per_event.
 *
 * Gives every per-event row its own photo files, remaps speaker links and
 * duplicate dismissals to per-event rows, exports then deletes Attendees
 * with no registration, deletes merged sources and ID card grid downloads,
 * and clears organization_id on attendee users. audit_logs are untouched.
 *
 * Database changes run in one transaction; file deletions run after it
 * commits, so a failed run leaves both rows and files in place and can be
 * re-run. Deleted rows are not restored by down(): Release 1 rolls back by
 * snapshot restore.
 *
 * Run it in the same deploy as split_attendees_per_event: rows created after
 * the split have no legacy_registration_id and are not remapped.
 */
return new class extends Migration
{
    private const EXPORT_PATH = 'exports/unregistered-attendees-2026-10-22.csv';

    private const EXPORT_COLUMNS = [
        'id', 'organization_id', 'organization_level', 'first_name', 'middle_name', 'last_name',
        'email_address', 'mobile_no', 'union_id', 'mission_id', 'church_id', 'remarks', 'user_id', 'created_at',
    ];

    public function up(): void
    {
        $this->giveEachRowItsOwnPhotos();
        $this->exportUnregisteredAttendees();

        $photoOwners = [];
        $gridPaths = [];

        DB::transaction(function () use (&$photoOwners, &$gridPaths) {
            [$personOf, $profiles] = $this->profileMaps();

            $this->remapSpeakerLinks($personOf, $profiles);
            $this->remapDuplicateDismissals($personOf, $profiles);
            $photoOwners = $this->deleteUnregisteredAndMergedAttendees();
            $gridPaths = $this->deleteGridDownloads();
            DB::table('users')->where('role', 'attendee')->whereNotNull('organization_id')->update(['organization_id' => null]);
        });

        $this->deleteFiles($photoOwners, $gridPaths);
    }

    public function down(): void
    {
        // Deleted rows and files are not recoverable here; restore the pre-migration snapshot.
    }

    /**
     * Copies each row's photos to attendees/{id}/photo-*.webp on the local
     * disk. A copied row starts with the original's paths, so any path that
     * belongs to another id is copied and rewritten. Legacy public files are
     * removed after the copy.
     */
    private function giveEachRowItsOwnPhotos(): void
    {
        $local = Storage::disk('local');
        $public = Storage::disk('public');
        $legacyFiles = [];

        $rows = DB::table('attendees')
            ->whereNotNull('event_id')
            ->whereNotNull('photo_paths')
            ->orderBy('id')
            ->select('id', 'photo_paths')
            ->lazyById(200);

        foreach ($rows as $row) {
            $paths = json_decode($row->photo_paths, true);

            if (! is_array($paths)) {
                continue;
            }

            $own = [];

            foreach ($paths as $size => $path) {
                $target = "attendees/{$row->id}/photo-{$size}.webp";

                if (! is_string($path) || ! preg_match('#^attendees/[0-9]+/photo-(sm|md|lg|original)\.webp$#D', $path)) {
                    continue;
                }

                if (! $local->exists($target)) {
                    if ($local->exists($path)) {
                        $local->copy($path, $target);
                    } elseif ($public->exists($path)) {
                        $local->put($target, $public->get($path));
                        $legacyFiles[$path] = true;
                    } else {
                        continue;
                    }
                }

                $own[$size] = $target;

                if ($path === $target && $public->exists($path)) {
                    $legacyFiles[$path] = true;
                }
            }

            $encoded = $own === [] ? null : json_encode($own);

            if ($encoded !== $row->photo_paths) {
                DB::table('attendees')->where('id', $row->id)->update(['photo_paths' => $encoded]);
            }
        }

        // Every copy is on the local disk now; the public files are no longer referenced.
        foreach (array_keys($legacyFiles) as $path) {
            $public->delete($path);
        }
    }

    private function exportUnregisteredAttendees(): void
    {
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, self::EXPORT_COLUMNS);
        $exported = 0;

        $rows = DB::table('attendees')
            ->whereNull('event_id')
            ->whereNull('merged_into_id')
            ->orderBy('id')
            ->select(self::EXPORT_COLUMNS)
            ->lazyById(500);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($value) => $this->csvCell($value), (array) $row));
            $exported++;
        }

        rewind($handle);

        // A re-run after the rows were deleted must not overwrite the only copy with a bare header.
        if ($exported > 0 || ! Storage::disk('local')->exists(self::EXPORT_PATH)) {
            Storage::disk('local')->put(self::EXPORT_PATH, stream_get_contents($handle));
        }

        fclose($handle);

        if (! Storage::disk('local')->exists(self::EXPORT_PATH)) {
            throw new RuntimeException('The unregistered Attendees CSV was not written; nothing was deleted.');
        }
    }

    /**
     * Stops a spreadsheet from running a name or remark as a formula.
     */
    private function csvCell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && str_contains("=+-@\t\r", $value[0]) && ! is_numeric($value)) {
            return "'{$value}";
        }

        return $value;
    }

    /**
     * personOf: attendee id => id of the person (the pre-split Attendee).
     * profiles: person id => event id => attendee id.
     * A row with no registration is its own person with no profile.
     *
     * @return array{array<int, int>, array<int, array<int, int>>}
     */
    private function profileMaps(): array
    {
        $personOf = [];
        $profiles = [];

        $rows = DB::table('attendees')
            ->join('event_registrations', 'event_registrations.id', '=', 'attendees.legacy_registration_id')
            ->orderBy('attendees.id')
            ->select('attendees.id', 'event_registrations.attendee_id as person_id', 'event_registrations.event_id')
            ->lazyById(500, 'attendees.id', 'id');

        foreach ($rows as $row) {
            $personOf[$row->id] = $row->person_id;
            $profiles[$row->person_id][$row->event_id] = $row->id;
        }

        return [$personOf, $profiles];
    }

    /**
     * @param  array<int, int>  $personOf
     * @param  array<int, array<int, int>>  $profiles
     */
    private function remapSpeakerLinks(array $personOf, array $profiles): void
    {
        $items = DB::table('event_program_items')
            ->join('event_programs', 'event_programs.id', '=', 'event_program_items.event_program_id')
            ->leftJoin('attendees', 'attendees.id', '=', 'event_program_items.attendee_id')
            ->whereNotNull('event_program_items.attendee_id')
            ->orderBy('event_program_items.id')
            ->select('event_program_items.id', 'event_program_items.attendee_id', 'event_program_items.participant_name', 'event_programs.event_id', 'attendees.first_name', 'attendees.last_name')
            ->lazyById(500, 'event_program_items.id', 'id');

        foreach ($items as $item) {
            $person = $personOf[$item->attendee_id] ?? $item->attendee_id;
            $profile = $profiles[$person][$item->event_id] ?? null;
            $update = ['attendee_id' => $profile];

            if ($profile === null && blank($item->participant_name) && $item->first_name !== null) {
                $update['participant_name'] = trim("{$item->first_name} {$item->last_name}");
            }

            if ($profile !== $item->attendee_id) {
                DB::table('event_program_items')->where('id', $item->id)->update($update);
            }
        }
    }

    /**
     * One dismissal per event where both people have a profile. Old rows
     * are replaced, so pairs with no shared event are dropped.
     *
     * @param  array<int, int>  $personOf
     * @param  array<int, array<int, int>>  $profiles
     */
    private function remapDuplicateDismissals(array $personOf, array $profiles): void
    {
        $eventOrganizations = DB::table('events')->pluck('organization_id', 'id');
        $dismissals = DB::table('attendee_duplicate_dismissals')->orderBy('id')->get();
        $remapped = [];

        foreach ($dismissals as $dismissal) {
            $one = $personOf[$dismissal->attendee_one_id] ?? $dismissal->attendee_one_id;
            $two = $personOf[$dismissal->attendee_two_id] ?? $dismissal->attendee_two_id;

            foreach (array_intersect_key($profiles[$one] ?? [], $profiles[$two] ?? []) as $eventId => $oneProfile) {
                $pair = [$oneProfile, $profiles[$two][$eventId]];
                sort($pair);

                $remapped[] = [
                    'organization_id' => $eventOrganizations[$eventId],
                    'attendee_one_id' => $pair[0],
                    'attendee_two_id' => $pair[1],
                    'name_fingerprint' => $dismissal->name_fingerprint,
                    'dismissed_by' => $dismissal->dismissed_by,
                    'dismissed_at' => $dismissal->dismissed_at,
                    'created_at' => $dismissal->created_at,
                    'updated_at' => $dismissal->updated_at,
                ];
            }
        }

        $key = fn (array|object $row) => implode(':', [((array) $row)['attendee_one_id'], ((array) $row)['attendee_two_id'], ((array) $row)['name_fingerprint']]);
        $keep = array_flip(array_map($key, $remapped));

        $stale = $dismissals->reject(fn ($dismissal) => isset($keep[$key($dismissal)]))->pluck('id')->all();

        foreach (array_chunk($stale, 500) as $chunk) {
            DB::table('attendee_duplicate_dismissals')->whereIn('id', $chunk)->delete();
        }

        foreach (array_chunk($remapped, 500) as $chunk) {
            DB::table('attendee_duplicate_dismissals')->insertOrIgnore($chunk);
        }
    }

    /**
     * Rows with no event are the Attendees with no registration. A merged
     * source that still holds a registration would take its attendance
     * records with it, so the run stops instead.
     *
     * @return list<int>
     */
    private function deleteUnregisteredAndMergedAttendees(): array
    {
        $mergedWithEvent = DB::table('attendees')->whereNotNull('merged_into_id')->whereNotNull('event_id')->count();

        if ($mergedWithEvent > 0) {
            throw new RuntimeException("{$mergedWithEvent} merged source Attendees still hold a registration; nothing was deleted.");
        }

        $ids = DB::table('attendees')->where(fn ($where) => $where->whereNull('event_id')->orWhereNotNull('merged_into_id'))->pluck('id')->all();

        // merged_into_id is restrictOnDelete, and a source can point at another source.
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('attendees')->whereIn('id', $chunk)->update(['merged_into_id' => null]);
            DB::table('attendees')->whereIn('id', $chunk)->delete();
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function deleteGridDownloads(): array
    {
        $paths = DB::table('id_card_grid_downloads')->whereNotNull('file_path')->pluck('file_path')->all();
        DB::table('id_card_grid_downloads')->delete();

        return $paths;
    }

    /**
     * The rows are already gone, so a failed file delete is logged, not thrown:
     * a re-run would find nothing left to clean.
     *
     * @param  list<int>  $photoOwners
     * @param  list<string>  $gridPaths
     */
    private function deleteFiles(array $photoOwners, array $gridPaths): void
    {
        foreach ($photoOwners as $id) {
            foreach (['local', 'public'] as $disk) {
                try {
                    Storage::disk($disk)->deleteDirectory("attendees/{$id}");
                } catch (Throwable $exception) {
                    Log::warning('Could not delete photos of a removed Attendee.', ['attendee_id' => $id, 'disk' => $disk, 'error' => $exception->getMessage()]);
                }
            }
        }

        foreach ($gridPaths as $path) {
            if ($path === '' || str_contains($path, '..')) {
                Log::warning('Skipped an unsafe ID card grid path during cleanup.', ['path' => $path]);

                continue;
            }

            try {
                Storage::disk('local')->delete($path);
            } catch (Throwable $exception) {
                Log::warning('Could not delete an ID card grid file.', ['path' => $path, 'error' => $exception->getMessage()]);
            }
        }
    }
};
