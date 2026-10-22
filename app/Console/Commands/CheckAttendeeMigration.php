<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

#[Signature('app:check-attendee-migration {--phase= : "before" or "after"; detected from the schema when omitted}')]
#[Description('Report what the per-event Attendee migration will change, then verify it afterwards')]
class CheckAttendeeMigration extends Command
{
    private const BASELINE_PATH = 'exports/attendee-migration-baseline.json';

    public function handle(): int
    {
        $migrated = Schema::hasColumn('attendees', 'legacy_registration_id');
        $phase = $this->option('phase') ?: ($migrated ? 'after' : 'before');

        if (! in_array($phase, ['before', 'after'], true)) {
            $this->error('The phase must be "before" or "after".');

            return self::FAILURE;
        }

        if ($phase === 'before' && $migrated) {
            $this->error('The split migration already ran; run the "after" phase.');

            return self::FAILURE;
        }

        if ($phase === 'after' && ! $migrated) {
            $this->error('The split migration has not run; run the "before" phase.');

            return self::FAILURE;
        }

        $checks = $phase === 'before' ? $this->reportBeforeMigrating() : $this->verifyAfterMigrating();

        $this->table(['Check', 'Result', 'Detail'], array_map(
            fn (array $check) => [$check['name'], $check['ok'] ? 'ok' : 'FAILED', $check['detail']],
            $checks,
        ));

        $failed = count(array_filter($checks, fn (array $check) => ! $check['ok']));

        if ($failed > 0) {
            $this->error("{$failed} check(s) failed.");

            return self::FAILURE;
        }

        $this->info('All checks passed.');

        return self::SUCCESS;
    }

    /**
     * @return list<array{name: string, ok: bool, detail: string}>
     */
    private function reportBeforeMigrating(): array
    {
        $registrations = DB::table('event_registrations')->count();
        $attendance = DB::table('attendance_records')->count();

        $unregistered = DB::table('attendees')
            ->whereNull('merged_into_id')
            ->whereNotExists(fn ($query) => $query->from('event_registrations')->whereColumn('event_registrations.attendee_id', 'attendees.id'))
            ->count();

        $mergedSources = DB::table('attendees')->whereNotNull('merged_into_id')->count();

        $mergedWithRegistration = DB::table('attendees')
            ->whereNotNull('merged_into_id')
            ->whereExists(fn ($query) => $query->from('event_registrations')->whereColumn('event_registrations.attendee_id', 'attendees.id'))
            ->count();

        [$pairs, $kept, $rows] = $this->dismissalPairs();

        $links = DB::table('event_program_items')->whereNotNull('attendee_id')->count();
        $nulled = DB::table('event_program_items')
            ->join('event_programs', 'event_programs.id', '=', 'event_program_items.event_program_id')
            ->whereNotNull('event_program_items.attendee_id')
            ->whereNotExists(fn ($query) => $query->from('event_registrations')
                ->whereColumn('event_registrations.attendee_id', 'event_program_items.attendee_id')
                ->whereColumn('event_registrations.event_id', 'event_programs.event_id'))
            ->count();

        Storage::disk('local')->put(self::BASELINE_PATH, json_encode([
            'registrations' => $registrations,
            'attendance_records' => $attendance,
            'recorded_at' => now()->toIso8601String(),
        ]));

        return [
            $this->check('Registrations (become Attendee rows)', true, (string) $registrations),
            $this->check('Attendance records (baseline saved)', true, (string) $attendance),
            $this->check('Attendees with no registration (exported, deleted)', true, (string) $unregistered),
            $this->check('Merged sources (deleted)', true, (string) $mergedSources),
            $this->check(
                'Merged sources holding a registration',
                $mergedWithRegistration === 0,
                $mergedWithRegistration === 0 ? '0' : "{$mergedWithRegistration}; the clean-up migration would stop",
            ),
            $this->check('Dismissal pairs kept', true, "{$kept} of {$pairs}, as {$rows} per-event row(s)"),
            $this->check('Dismissal pairs dropped', true, (string) ($pairs - $kept)),
            $this->check('Speaker links nulled', true, "{$nulled} of {$links}"),
        ];
    }

    /**
     * A pair is kept once per event where both people are registered.
     *
     * @return array{int, int, int} pairs, pairs kept, per-event rows
     */
    private function dismissalPairs(): array
    {
        $pairs = 0;
        $kept = 0;
        $rows = 0;

        $eventsOf = DB::table('event_registrations')->select('attendee_id', 'event_id')->get()->groupBy('attendee_id')->map(fn ($rows) => $rows->pluck('event_id')->all());

        foreach (DB::table('attendee_duplicate_dismissals')->orderBy('id')->lazyById(500) as $dismissal) {
            $pairs++;
            $shared = count(array_intersect($eventsOf[$dismissal->attendee_one_id] ?? [], $eventsOf[$dismissal->attendee_two_id] ?? []));

            if ($shared > 0) {
                $kept++;
                $rows += $shared;
            }
        }

        return [$pairs, $kept, $rows];
    }

    /**
     * @return list<array{name: string, ok: bool, detail: string}>
     */
    private function verifyAfterMigrating(): array
    {
        $baseline = $this->readBaseline();
        $perEvent = DB::table('attendees')->whereNotNull('legacy_registration_id')->count();
        $attendance = DB::table('attendance_records')->count();

        $checks = [];

        $checks[] = $baseline === null
            ? $this->check('Baseline from the "before" run', false, 'missing; counts cannot be compared')
            : $this->check('Baseline from the "before" run', true, "saved {$baseline['recorded_at']}");

        // legacy_registration_id is unique, so equal counts plus no unmapped registration means one row each.
        $unmapped = Schema::hasTable('event_registrations')
            ? DB::table('event_registrations')->whereNotExists(fn ($query) => $query->from('attendees')->whereColumn('attendees.legacy_registration_id', 'event_registrations.id'))->count()
            : 0;

        $checks[] = $this->check(
            'Attendee rows equal former registrations',
            $baseline !== null && $perEvent === $baseline['registrations'] && $unmapped === 0,
            "{$perEvent} rows, ".($baseline['registrations'] ?? '?')." registrations, {$unmapped} with no row",
        );

        $checks[] = $this->check(
            'Attendance count unchanged',
            $baseline !== null && $attendance === $baseline['attendance_records'],
            "{$attendance} records, ".($baseline['attendance_records'] ?? '?').' before',
        );

        $withoutAttendee = DB::table('attendance_records')
            ->whereNotExists(fn ($query) => $query->from('attendees')->whereColumn('attendees.id', 'attendance_records.attendee_id'))
            ->count();
        $checks[] = $this->check('Attendance records point at an Attendee', $withoutAttendee === 0, "{$withoutAttendee} orphaned");

        $withoutEvent = DB::table('attendees')->whereNull('event_id')->count();
        $checks[] = $this->check('Every Attendee row belongs to an event', $withoutEvent === 0, "{$withoutEvent} without an event");

        $checks[] = $this->verifyQrTokens();
        $checks[] = $this->verifyPhotos();

        return $checks;
    }

    private function verifyQrTokens(): array
    {
        $blank = DB::table('attendees')->whereNotNull('legacy_registration_id')->where(fn ($where) => $where->whereNull('qr_token')->orWhere('qr_token', ''))->count();
        $unresolved = 0;

        // The old table stays until the end of the Release 1 chain; once it is gone only the blank check can run.
        if (Schema::hasTable('event_registrations')) {
            $unresolved = DB::table('event_registrations')
                ->whereNotNull('qr_token')
                ->whereNotExists(fn ($query) => $query->from('attendees')->whereColumn('attendees.qr_token', 'event_registrations.qr_token'))
                ->count();
        }

        return $this->check('Every qr_token resolves', $blank + $unresolved === 0, "{$unresolved} unresolved, {$blank} blank");
    }

    private function verifyPhotos(): array
    {
        $disk = Storage::disk('local');
        $checked = 0;
        $missing = [];

        $rows = DB::table('attendees')->whereNotNull('photo_paths')->select('id', 'photo_paths')->lazyById(200);

        foreach ($rows as $row) {
            foreach ((array) json_decode($row->photo_paths, true) as $path) {
                $checked++;

                if (! is_string($path) || ! str_starts_with($path, "attendees/{$row->id}/") || ! $disk->exists($path)) {
                    $missing[] = "#{$row->id}";
                }
            }
        }

        $listed = implode(', ', array_slice(array_unique($missing), 0, 10));

        return $this->check(
            'Every photo path exists and is the row\'s own',
            $missing === [],
            $missing === [] ? "{$checked} path(s)" : count($missing)." of {$checked} missing (Attendees {$listed})",
        );
    }

    /**
     * @return array{registrations: int, attendance_records: int, recorded_at: string}|null
     */
    private function readBaseline(): ?array
    {
        $disk = Storage::disk('local');

        if (! $disk->exists(self::BASELINE_PATH)) {
            return null;
        }

        $data = json_decode((string) $disk->get(self::BASELINE_PATH), true);

        if (! is_array($data) || ! isset($data['registrations'], $data['attendance_records'])) {
            return null;
        }

        return [
            'registrations' => (int) $data['registrations'],
            'attendance_records' => (int) $data['attendance_records'],
            'recorded_at' => (string) ($data['recorded_at'] ?? 'unknown'),
        ];
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function check(string $name, bool $ok, string $detail): array
    {
        return ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    }
}
