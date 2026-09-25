<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->rebuildNames(false);
        $this->rebuildDismissals(false);
    }

    public function down(): void
    {
        $this->rebuildNames(true);
        $this->rebuildDismissals(true);
    }

    private function rebuildNames(bool $includeMiddle): void
    {
        DB::table('attendees')->select('id', 'first_name', 'middle_name', 'last_name')
            ->chunkById(500, function ($attendees) use ($includeMiddle) {
                foreach ($attendees as $attendee) {
                    DB::table('attendees')->where('id', $attendee->id)
                        ->update(['normalized_name' => $this->nameKey($attendee, $includeMiddle)]);
                }
            });
    }

    private function rebuildDismissals(bool $includeMiddle): void
    {
        DB::table('attendee_duplicate_dismissals')->select('id', 'organization_id', 'attendee_one_id', 'attendee_two_id', 'name_fingerprint')
            ->chunkById(500, function ($dismissals) use ($includeMiddle) {
                foreach ($dismissals as $dismissal) {
                    $attendees = DB::table('attendees')
                        ->whereIn('id', [$dismissal->attendee_one_id, $dismissal->attendee_two_id])
                        ->get(['first_name', 'middle_name', 'last_name']);
                    if ($attendees->count() !== 2) {
                        continue;
                    }

                    $oldKeys = $attendees->map(fn ($attendee) => $this->nameKey($attendee, ! $includeMiddle));
                    $newKeys = $attendees->map(fn ($attendee) => $this->nameKey($attendee, $includeMiddle));
                    if ($oldKeys->unique()->count() !== 1 || $newKeys->unique()->count() !== 1
                        || $dismissal->name_fingerprint !== hash('sha256', $oldKeys->first())) {
                        continue;
                    }

                    $newFingerprint = hash('sha256', $newKeys->first());
                    if (DB::table('attendee_duplicate_dismissals')
                        ->where('organization_id', $dismissal->organization_id)
                        ->where('attendee_one_id', $dismissal->attendee_one_id)
                        ->where('attendee_two_id', $dismissal->attendee_two_id)
                        ->where('name_fingerprint', $newFingerprint)
                        ->exists()) {
                        continue;
                    }

                    DB::table('attendee_duplicate_dismissals')->where('id', $dismissal->id)
                        ->update(['name_fingerprint' => $newFingerprint]);
                }
            });
    }

    private function nameKey(object $attendee, bool $includeMiddle): string
    {
        $parts = $includeMiddle
            ? [$attendee->first_name, $attendee->middle_name, $attendee->last_name]
            : [$attendee->first_name, $attendee->last_name];

        return collect($parts)->filter()
            ->map(fn (string $part) => preg_replace('/\s+/', ' ', trim(mb_strtolower($part))))
            ->implode(' ');
    }
};
