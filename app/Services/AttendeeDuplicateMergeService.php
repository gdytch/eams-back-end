<?php

namespace App\Services;

use App\Jobs\GenerateAttendeeIdCardJob;
use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\EventProgramItem;
use App\Models\EventRegistration;
use App\Models\AuditLog;
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

            $registrationSummary = $this->moveRegistrations($primary, $sources);
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

            foreach ($registrationSummary['retained_registration_ids'] as $registrationId) {
                GenerateAttendeeIdCardJob::dispatch(EventRegistration::find($registrationId))->afterCommit();
            }

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

    /** @param Collection<int, Attendee> $sources */
    private function moveRegistrations(Attendee $primary, Collection $sources): array
    {
        $allAttendeeIds = $sources->pluck('id')->prepend($primary->id);
        $registrations = EventRegistration::query()
            ->whereIn('attendee_id', $allAttendeeIds)
            ->lockForUpdate()
            ->get();
        $records = AttendanceRecord::query()
            ->whereIn('event_registration_id', $registrations->pluck('id'))
            ->lockForUpdate()
            ->get()
            ->groupBy('event_registration_id');

        $primaryByEvent = $registrations->where('attendee_id', $primary->id)->keyBy('event_id');
        $retainedIds = $primaryByEvent->pluck('id')->all();
        $movedRegistrationIds = [];
        $removedRegistrationIds = [];
        $mergedAttendanceCount = 0;

        foreach ($registrations->whereIn('attendee_id', $sources->pluck('id')) as $sourceRegistration) {
            $target = $primaryByEvent->get($sourceRegistration->event_id);
            if ($target === null) {
                $sourceRegistration->update(['attendee_id' => $primary->id]);
                $primaryByEvent->put($sourceRegistration->event_id, $sourceRegistration);
                $retainedIds[] = $sourceRegistration->id;
                $movedRegistrationIds[] = $sourceRegistration->id;
                continue;
            }

            $targetRecords = $records->get($target->id, collect())->keyBy('event_session_id');
            foreach ($records->get($sourceRegistration->id, collect()) as $sourceRecord) {
                $targetRecord = $targetRecords->get($sourceRecord->event_session_id);
                if ($targetRecord === null) {
                    $sourceRecord->update(['event_registration_id' => $target->id]);
                    $targetRecords->put($sourceRecord->event_session_id, $sourceRecord);
                    continue;
                }

                $this->mergeAttendanceRecord($targetRecord, $sourceRecord);
                $sourceRecord->delete();
                $mergedAttendanceCount++;
            }

            $sourceRegistration->delete();
            $removedRegistrationIds[] = $sourceRegistration->id;
        }

        return [
            'retained_registration_ids' => array_values(array_unique($retainedIds)),
            'moved_registration_ids' => $movedRegistrationIds,
            'removed_registration_ids' => $removedRegistrationIds,
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
