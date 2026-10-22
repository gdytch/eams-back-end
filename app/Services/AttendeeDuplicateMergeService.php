<?php

namespace App\Services;

use App\Jobs\GenerateAttendeeIdCardJob;
use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\EventProgramItem;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AttendeeDuplicateMergeService
{
    /**
     * @param  array<int>  $duplicateIds
     */
    public function merge(User $actor, int $primaryId, array $duplicateIds): Attendee
    {
        return DB::transaction(function () use ($actor, $primaryId, $duplicateIds) {
            $ids = collect([$primaryId, ...$duplicateIds])->map(fn ($id) => (int) $id)->unique()->values();

            if ($ids->count() < 2 || ! $ids->contains($primaryId)) {
                throw new DomainException('Select a primary attendee and at least one duplicate.');
            }

            $attendees = Attendee::withoutGlobalScopes()
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($attendees->count() !== $ids->count()) {
                throw new DomainException('One or more attendees no longer exist.');
            }

            /** @var Attendee $primary */
            $primary = $attendees->get($primaryId);
            $sources = $attendees->except($primaryId)->sortByDesc('updated_at')->values();

            if ($attendees->contains(fn (Attendee $attendee) => $attendee->merged_at !== null)
                || $attendees->pluck('organization_id')->unique()->count() !== 1
                || $attendees->map(fn (Attendee $attendee) => Attendee::normalizeName($attendee->first_name, $attendee->last_name))->unique()->count() !== 1) {
                throw new DomainException('Attendees must be active duplicates from one organization.');
            }

            if ($attendees->pluck('event_id')->unique()->count() !== 1) {
                throw new DomainException('Attendees must be duplicates registered for the same event.');
            }

            $linkedUserIds = $attendees->pluck('user_id')->filter()->unique()->values();
            if ($linkedUserIds->count() > 1) {
                throw new DomainException('Merge blocked: selected attendees have multiple linked accounts.');
            }

            $sourceSnapshots = $sources->mapWithKeys(fn (Attendee $source) => [$source->id => $source->attributesToArray()])->all();
            $fieldFills = $this->fillPrimaryBlanks($primary, $sources);
            $transferredUserId = $primary->user_id === null ? $linkedUserIds->first() : null;

            // Clear unique/claimable source fields before moving any of them onto primary.
            foreach ($sources as $source) {
                $source->forceFill([
                    'email_address' => null,
                    'invite_token' => null,
                    'invited_at' => null,
                    'user_id' => null,
                ])->save();
            }

            if ($transferredUserId !== null) {
                $fieldFills['user_id'] = $transferredUserId;
            }

            if ($fieldFills !== []) {
                $primary->forceFill($fieldFills)->save();
            }

            if ($transferredUserId !== null) {
                $this->syncLinkedUserName($primary, $transferredUserId);
            }

            $registrationSummary = $this->moveAttendance($primary, $sources);
            EventProgramItem::query()
                ->whereIn('attendee_id', $sources->pluck('id'))
                ->update(['attendee_id' => $primary->id]);

            foreach ($sources as $source) {
                $source->forceFill([
                    'merged_into_id' => $primary->id,
                    'merged_by' => $actor->id,
                    'merged_at' => now(),
                ])->save();
            }

            AuditLog::record('attendee.merged', $primary, [
                'primary_attendee_id' => $primary->id,
                'source_attendee_ids' => $sources->pluck('id')->all(),
                'source_snapshots' => $sourceSnapshots,
                'primary_field_fills' => $fieldFills,
                'registrations' => $registrationSummary,
            ]);

            GenerateAttendeeIdCardJob::dispatch($primary)->afterCommit();

            return $primary->fresh(['union', 'mission', 'church']);
        });
    }

    /** @param Collection<int, Attendee> $sources */
    private function fillPrimaryBlanks(Attendee $primary, Collection $sources): array
    {
        $fills = [];
        foreach (['mobile_no', 'email_address', 'remarks', 'photo_paths'] as $field) {
            if (! blank($primary->getAttribute($field))) {
                continue;
            }

            $value = $sources->map(fn (Attendee $source) => $source->getAttribute($field))->first(fn ($value) => ! blank($value));
            if (! blank($value)) {
                $fills[$field] = $value;
            }
        }

        if ($primary->organization_level === null) {
            $territorySource = $sources->first(fn (Attendee $source) => $source->organization_level !== null);
            if ($territorySource !== null) {
                foreach (['organization_level', 'union_id', 'mission_id', 'church_id'] as $field) {
                    $fills[$field] = $territorySource->getAttribute($field);
                }
            }
        }

        return $fills;
    }

    private function syncLinkedUserName(Attendee $attendee, int $userId): void
    {
        $user = User::withoutGlobalScopes()->find($userId);
        if ($user === null) {
            return;
        }

        $user->update([
            'first_name' => $attendee->first_name,
            'middle_name' => $attendee->middle_name,
            'last_name' => $attendee->last_name,
            'name' => trim(collect([$attendee->first_name, $attendee->middle_name, $attendee->last_name])->filter()->implode(' ')),
        ]);
    }

    /**
     * Fold the sources' attendance into the primary; every attendee is one registration in the same event.
     *
     * @param  Collection<int, Attendee>  $sources
     */
    private function moveAttendance(Attendee $primary, Collection $sources): array
    {
        $records = AttendanceRecord::query()
            ->whereIn('attendee_id', $sources->pluck('id')->prepend($primary->id))
            ->lockForUpdate()
            ->get()
            ->groupBy('attendee_id');

        $primaryRecords = $records->get($primary->id, collect())->keyBy(fn (AttendanceRecord $record) => $record->event_session_id ?? 'b'.$record->breakout_id);
        $mergedAttendanceCount = 0;

        foreach ($sources as $source) {
            foreach ($records->get($source->id, collect()) as $sourceRecord) {
                $slotKey = $sourceRecord->event_session_id ?? 'b'.$sourceRecord->breakout_id;
                $targetRecord = $primaryRecords->get($slotKey);
                if ($targetRecord === null) {
                    $sourceRecord->update(['attendee_id' => $primary->id]);
                    $primaryRecords->put($slotKey, $sourceRecord);

                    continue;
                }

                $this->mergeAttendanceRecord($targetRecord, $sourceRecord);
                $sourceRecord->delete();
                $mergedAttendanceCount++;
            }
        }

        return [
            'retained_registration_ids' => [$primary->id],
            'moved_registration_ids' => [],
            'removed_registration_ids' => $sources->pluck('id')->all(),
            'merged_attendance_records' => $mergedAttendanceCount,
        ];
    }

    private function mergeAttendanceRecord(AttendanceRecord $target, AttendanceRecord $source): void
    {
        $checkIns = collect([$target, $source])->filter(fn (AttendanceRecord $record) => $record->check_in_at !== null)
            ->sortBy('check_in_at');
        $winner = $checkIns->first() ?? $target;
        $checkOut = collect([$target->check_out_at, $source->check_out_at])->filter()->sort()->last();

        $target->update([
            'check_in_at' => $checkIns->first()?->check_in_at,
            'check_out_at' => $checkOut,
            'method' => $winner->method,
            'recorded_by' => $winner->recorded_by,
        ]);
    }
}
