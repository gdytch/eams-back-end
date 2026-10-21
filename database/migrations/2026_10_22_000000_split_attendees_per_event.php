<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Release 1: one attendees row per (event, person).
 *
 * The earliest registration of each Attendee keeps the original row; every
 * other registration gets a copied row. Registration fields move onto the
 * row, and attendance records are remapped through legacy_registration_id.
 *
 * up() can be re-run after a partial failure: each step skips work that is
 * already done. event_registrations is kept; it is dropped at the end of the
 * Release 1 migration chain.
 */
return new class extends Migration
{
    /**
     * Columns copied from the original row to each further event's row.
     */
    private const IDENTITY_COLUMNS = [
        'organization_level', 'union_id', 'mission_id', 'church_id',
        'first_name', 'middle_name', 'last_name', 'normalized_name',
        'photo_paths', 'mobile_no', 'email_address', 'remarks',
        'user_id', 'created_by', 'merged_into_id', 'merged_by', 'merged_at',
        'created_at',
    ];

    private const REGISTRATION_COLUMNS = [
        'qr_token', 'id_card_path', 'id_card_images_path', 'id_card_generated_at',
        'registered_by', 'registered_at',
    ];

    public function up(): void
    {
        $this->addRegistrationColumnsToAttendees();
        $this->scopeAttendeeUniqueIndexesToEvent();
        DB::transaction(fn () => $this->splitAttendeesPerEvent());
        $this->remapAttendanceToAttendees();
    }

    public function down(): void
    {
        $this->ensureRollbackKeepsData();
        $this->remapAttendanceToRegistrations();
        DB::transaction(function () {
            $this->writeRegistrationColumnsBack();
            $this->deleteCopiedAttendees();
        });
        $this->dropAttendeeForeignKeysToEventsAndUsers();
        $this->restoreGlobalAttendeeUniqueIndexes();
        $this->dropRegistrationColumnsFromAttendees();
    }

    private function addRegistrationColumnsToAttendees(): void
    {
        if (Schema::hasColumn('attendees', 'legacy_registration_id')) {
            return;
        }

        Schema::table('attendees', function (Blueprint $table) {
            $table->foreignId('event_id')->nullable()->after('organization_id')->constrained()->cascadeOnDelete();
            $table->string('qr_token', 512)->nullable()->unique()->after('event_id');
            $table->string('id_card_path')->nullable()->after('qr_token');
            $table->json('id_card_images_path')->nullable()->after('id_card_path');
            $table->timestamp('id_card_generated_at')->nullable()->after('id_card_images_path');
            $table->foreignId('registered_by')->nullable()->after('id_card_generated_at')->constrained('users')->nullOnDelete();
            $table->timestamp('registered_at')->nullable()->after('registered_by');
            $table->unsignedBigInteger('legacy_registration_id')->nullable()->unique()->after('registered_at');
        });
    }

    private function scopeAttendeeUniqueIndexesToEvent(): void
    {
        if (! Schema::hasIndex('attendees', 'attendees_user_id_unique')) {
            return;
        }

        Schema::table('attendees', function (Blueprint $table) {
            // MySQL needs an index on user_id for its foreign key once the unique index is gone.
            $table->index('user_id');
            $table->dropUnique(['user_id']);
            $table->dropUnique(['email_address']);
            $table->unique(['event_id', 'user_id']);
            $table->unique(['event_id', 'email_address']);
        });
    }

    private function splitAttendeesPerEvent(): void
    {
        $mapped = DB::table('attendees')->whereNotNull('legacy_registration_id')->pluck('legacy_registration_id')->flip();
        $eventOrganizations = DB::table('events')->pluck('organization_id', 'id');
        $original = null;

        $registrations = DB::table('event_registrations')
            ->orderBy('attendee_id')
            ->orderBy('registered_at')
            ->orderBy('id')
            ->lazy(500);

        foreach ($registrations as $registration) {
            $isEarliest = $original?->id !== $registration->attendee_id;

            if ($isEarliest) {
                $original = DB::table('attendees')->find($registration->attendee_id);
            }

            if ($mapped->has($registration->id)) {
                continue;
            }

            $fields = ['event_id' => $registration->event_id, 'legacy_registration_id' => $registration->id];

            foreach (self::REGISTRATION_COLUMNS as $column) {
                $fields[$column] = $registration->{$column};
            }

            $kept = $isEarliest && DB::table('attendees')
                ->where('id', $original->id)
                ->whereNull('legacy_registration_id')
                ->update($fields) === 1;

            if (! $kept) {
                DB::table('attendees')->insert([
                    ...$this->identityOf($original),
                    ...$fields,
                    'organization_id' => $eventOrganizations[$registration->event_id],
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function identityOf(object $attendee): array
    {
        $identity = [];

        foreach (self::IDENTITY_COLUMNS as $column) {
            $identity[$column] = $attendee->{$column};
        }

        return $identity;
    }

    private function remapAttendanceToAttendees(): void
    {
        if (! Schema::hasColumn('attendance_records', 'event_registration_id')) {
            return;
        }

        if (! Schema::hasColumn('attendance_records', 'attendee_id')) {
            Schema::table('attendance_records', function (Blueprint $table) {
                $table->unsignedBigInteger('attendee_id')->nullable()->after('id');
            });
        }

        DB::table('attendance_records')->update([
            'attendee_id' => DB::raw('(select attendees.id from attendees where attendees.legacy_registration_id = attendance_records.event_registration_id)'),
        ]);

        $unmapped = DB::table('attendance_records')->whereNull('attendee_id')->count();

        if ($unmapped > 0) {
            throw new RuntimeException("{$unmapped} attendance records have no per-event Attendee; event_registration_id was kept.");
        }

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropForeign(['event_registration_id']);
            $table->dropUnique(['event_registration_id', 'event_session_id']);
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn('event_registration_id');
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->unsignedBigInteger('attendee_id')->nullable(false)->change();
            $table->unique(['attendee_id', 'event_session_id']);
            $table->foreign('attendee_id')->references('id')->on('attendees')->cascadeOnDelete();
        });
    }

    /**
     * Rows created after the split have no registration to return to, so
     * rolling back would lose them. Release 1 rolls back by snapshot restore.
     */
    private function ensureRollbackKeepsData(): void
    {
        if (! Schema::hasColumn('attendees', 'legacy_registration_id')) {
            return;
        }

        $unmapped = DB::table('attendees')
            ->whereNotNull('event_id')
            ->whereNull('legacy_registration_id')
            ->count();

        if ($unmapped > 0) {
            throw new RuntimeException("{$unmapped} Attendees were created after the per-event split; restore the pre-migration snapshot instead of rolling back.");
        }
    }

    private function remapAttendanceToRegistrations(): void
    {
        if (! Schema::hasColumn('attendance_records', 'attendee_id')) {
            return;
        }

        if (! Schema::hasColumn('attendance_records', 'event_registration_id')) {
            Schema::table('attendance_records', function (Blueprint $table) {
                $table->unsignedBigInteger('event_registration_id')->nullable()->after('id');
            });
        }

        DB::table('attendance_records')->update([
            'event_registration_id' => DB::raw('(select attendees.legacy_registration_id from attendees where attendees.id = attendance_records.attendee_id)'),
        ]);

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropForeign(['attendee_id']);
            $table->dropUnique(['attendee_id', 'event_session_id']);
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn('attendee_id');
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->unsignedBigInteger('event_registration_id')->nullable(false)->change();
            $table->unique(['event_registration_id', 'event_session_id']);
            $table->foreign('event_registration_id')->references('id')->on('event_registrations')->cascadeOnDelete();
        });
    }

    /**
     * ID cards may have been regenerated after the split; keep the newest paths.
     */
    private function writeRegistrationColumnsBack(): void
    {
        if (! Schema::hasColumn('attendees', 'legacy_registration_id')) {
            return;
        }

        $attendees = DB::table('attendees')
            ->whereNotNull('legacy_registration_id')
            ->orderBy('id')
            ->lazy(500);

        foreach ($attendees as $attendee) {
            $fields = [];

            foreach (self::REGISTRATION_COLUMNS as $column) {
                $fields[$column] = $attendee->{$column};
            }

            DB::table('event_registrations')->where('id', $attendee->legacy_registration_id)->update($fields);
        }
    }

    private function deleteCopiedAttendees(): void
    {
        if (! Schema::hasColumn('attendees', 'legacy_registration_id')) {
            return;
        }

        DB::table('attendees')
            ->whereNotNull('legacy_registration_id')
            ->whereNotExists(fn ($query) => $query
                ->from('event_registrations')
                ->whereColumn('event_registrations.id', 'attendees.legacy_registration_id')
                ->whereColumn('event_registrations.attendee_id', 'attendees.id'))
            ->delete();
    }

    /**
     * On MySQL the event_id foreign key uses a per-event unique index, so it
     * goes first.
     */
    private function dropAttendeeForeignKeysToEventsAndUsers(): void
    {
        if (! Schema::hasColumn('attendees', 'event_id')) {
            return;
        }

        $foreignKeys = collect(Schema::getForeignKeys('attendees'))->pluck('columns')->flatten();

        Schema::table('attendees', function (Blueprint $table) use ($foreignKeys) {
            if ($foreignKeys->contains('event_id')) {
                $table->dropForeign(['event_id']);
            }

            if ($foreignKeys->contains('registered_by')) {
                $table->dropForeign(['registered_by']);
            }
        });
    }

    private function restoreGlobalAttendeeUniqueIndexes(): void
    {
        if (Schema::hasIndex('attendees', 'attendees_user_id_unique')) {
            return;
        }

        Schema::table('attendees', function (Blueprint $table) {
            $table->dropUnique(['event_id', 'user_id']);
            $table->dropUnique(['event_id', 'email_address']);
            $table->unique('user_id');
            $table->unique('email_address');
            $table->dropIndex(['user_id']);
        });
    }

    private function dropRegistrationColumnsFromAttendees(): void
    {
        if (! Schema::hasColumn('attendees', 'legacy_registration_id')) {
            return;
        }

        Schema::table('attendees', function (Blueprint $table) {
            $table->dropUnique(['qr_token']);
            $table->dropUnique(['legacy_registration_id']);
        });

        Schema::table('attendees', function (Blueprint $table) {
            $table->dropColumn(['event_id', 'legacy_registration_id', ...self::REGISTRATION_COLUMNS]);
        });
    }
};
