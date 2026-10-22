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
 * up() and down() can be re-run after a partial failure. MySQL commits each
 * DDL statement on its own, so every statement checks its own state first.
 * event_registrations is kept; it is dropped at the end of the Release 1
 * migration chain.
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
        $this->addColumn('attendees', 'event_id', fn (Blueprint $table) => $table->unsignedBigInteger('event_id')->nullable()->after('organization_id'));

        if (! $this->hasForeignKey('attendees', 'event_id')) {
            Schema::table('attendees', fn (Blueprint $table) => $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete());
        }

        $this->addColumn('attendees', 'qr_token', fn (Blueprint $table) => $table->string('qr_token', 512)->nullable()->after('event_id'));
        $this->addColumn('attendees', 'id_card_path', fn (Blueprint $table) => $table->string('id_card_path')->nullable()->after('qr_token'));
        $this->addColumn('attendees', 'id_card_images_path', fn (Blueprint $table) => $table->json('id_card_images_path')->nullable()->after('id_card_path'));
        $this->addColumn('attendees', 'id_card_generated_at', fn (Blueprint $table) => $table->timestamp('id_card_generated_at')->nullable()->after('id_card_images_path'));
        $this->addColumn('attendees', 'registered_by', fn (Blueprint $table) => $table->unsignedBigInteger('registered_by')->nullable()->after('id_card_generated_at'));

        if (! $this->hasForeignKey('attendees', 'registered_by')) {
            Schema::table('attendees', fn (Blueprint $table) => $table->foreign('registered_by')->references('id')->on('users')->nullOnDelete());
        }

        $this->addColumn('attendees', 'registered_at', fn (Blueprint $table) => $table->timestamp('registered_at')->nullable()->after('registered_by'));
        $this->addColumn('attendees', 'legacy_registration_id', fn (Blueprint $table) => $table->unsignedBigInteger('legacy_registration_id')->nullable()->after('registered_at'));

        if (! Schema::hasIndex('attendees', 'attendees_qr_token_unique')) {
            Schema::table('attendees', fn (Blueprint $table) => $table->unique('qr_token'));
        }

        if (! Schema::hasIndex('attendees', 'attendees_legacy_registration_id_unique')) {
            Schema::table('attendees', fn (Blueprint $table) => $table->unique('legacy_registration_id'));
        }
    }

    private function scopeAttendeeUniqueIndexesToEvent(): void
    {
        // MySQL needs an index on user_id for its foreign key once the unique index is gone.
        if (! Schema::hasIndex('attendees', 'attendees_user_id_index')) {
            Schema::table('attendees', fn (Blueprint $table) => $table->index('user_id'));
        }

        if (Schema::hasIndex('attendees', 'attendees_user_id_unique')) {
            Schema::table('attendees', fn (Blueprint $table) => $table->dropUnique(['user_id']));
        }

        if (Schema::hasIndex('attendees', 'attendees_email_address_unique')) {
            Schema::table('attendees', fn (Blueprint $table) => $table->dropUnique(['email_address']));
        }

        if (! Schema::hasIndex('attendees', 'attendees_event_id_user_id_unique')) {
            Schema::table('attendees', fn (Blueprint $table) => $table->unique(['event_id', 'user_id']));
        }

        if (! Schema::hasIndex('attendees', 'attendees_event_id_email_address_unique')) {
            Schema::table('attendees', fn (Blueprint $table) => $table->unique(['event_id', 'email_address']));
        }
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

            $fields = [
                'event_id' => $registration->event_id,
                'organization_id' => $eventOrganizations[$registration->event_id],
                'legacy_registration_id' => $registration->id,
            ];

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

    /**
     * Records are remapped only while event_registration_id still exists;
     * the finishing steps run on their own checks.
     */
    private function remapAttendanceToAttendees(): void
    {
        $this->addColumn('attendance_records', 'attendee_id', fn (Blueprint $table) => $table->unsignedBigInteger('attendee_id')->nullable()->after('id'));

        if (Schema::hasColumn('attendance_records', 'event_registration_id')) {
            DB::table('attendance_records')->update([
                'attendee_id' => DB::raw('(select attendees.id from attendees where attendees.legacy_registration_id = attendance_records.event_registration_id)'),
            ]);

            $unmapped = DB::table('attendance_records')->whereNull('attendee_id')->count();

            if ($unmapped > 0) {
                throw new RuntimeException("{$unmapped} attendance records have no per-event Attendee; event_registration_id was kept.");
            }

            $this->dropColumnWithKeys('attendance_records', 'event_registration_id', 'attendance_records_event_registration_id_event_session_id_unique');
        }

        $this->finishKeyColumn('attendance_records', 'attendee_id', 'attendees', 'attendance_records_attendee_id_event_session_id_unique');
    }

    /**
     * Rows created after the split have no registration to return to, so
     * rolling back would lose them. Release 1 rolls back by snapshot restore.
     */
    private function ensureRollbackKeepsData(): void
    {
        if (! Schema::hasColumn('attendees', 'legacy_registration_id') || ! Schema::hasColumn('attendees', 'event_id')) {
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
        $this->addColumn('attendance_records', 'event_registration_id', fn (Blueprint $table) => $table->unsignedBigInteger('event_registration_id')->nullable()->after('id'));

        if (Schema::hasColumn('attendance_records', 'attendee_id')) {
            DB::table('attendance_records')->update([
                'event_registration_id' => DB::raw('(select attendees.legacy_registration_id from attendees where attendees.id = attendance_records.attendee_id)'),
            ]);

            $this->dropColumnWithKeys('attendance_records', 'attendee_id', 'attendance_records_attendee_id_event_session_id_unique');
        }

        $this->finishKeyColumn('attendance_records', 'event_registration_id', 'event_registrations', 'attendance_records_event_registration_id_event_session_id_unique');
    }

    /**
     * ID cards may have been regenerated after the split; keep the newest paths.
     * The original row's organization_id is not restored: Release 1 rolls
     * back by snapshot restore.
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
        if ($this->hasForeignKey('attendees', 'event_id')) {
            Schema::table('attendees', fn (Blueprint $table) => $table->dropForeign(['event_id']));
        }

        if ($this->hasForeignKey('attendees', 'registered_by')) {
            Schema::table('attendees', fn (Blueprint $table) => $table->dropForeign(['registered_by']));
        }
    }

    private function restoreGlobalAttendeeUniqueIndexes(): void
    {
        if (Schema::hasIndex('attendees', 'attendees_event_id_user_id_unique')) {
            Schema::table('attendees', fn (Blueprint $table) => $table->dropUnique(['event_id', 'user_id']));
        }

        if (Schema::hasIndex('attendees', 'attendees_event_id_email_address_unique')) {
            Schema::table('attendees', fn (Blueprint $table) => $table->dropUnique(['event_id', 'email_address']));
        }

        if (! Schema::hasIndex('attendees', 'attendees_user_id_unique')) {
            Schema::table('attendees', fn (Blueprint $table) => $table->unique('user_id'));
        }

        if (! Schema::hasIndex('attendees', 'attendees_email_address_unique')) {
            Schema::table('attendees', fn (Blueprint $table) => $table->unique('email_address'));
        }

        // The unique index now backs the user_id foreign key on MySQL.
        if (Schema::hasIndex('attendees', 'attendees_user_id_index')) {
            Schema::table('attendees', fn (Blueprint $table) => $table->dropIndex(['user_id']));
        }
    }

    /**
     * legacy_registration_id goes first: once it is gone, the data steps of
     * a re-run down() skip themselves.
     */
    private function dropRegistrationColumnsFromAttendees(): void
    {
        if (Schema::hasIndex('attendees', 'attendees_qr_token_unique')) {
            Schema::table('attendees', fn (Blueprint $table) => $table->dropUnique(['qr_token']));
        }

        if (Schema::hasIndex('attendees', 'attendees_legacy_registration_id_unique')) {
            Schema::table('attendees', fn (Blueprint $table) => $table->dropUnique(['legacy_registration_id']));
        }

        $columns = array_values(array_filter(
            ['legacy_registration_id', 'event_id', ...self::REGISTRATION_COLUMNS],
            fn (string $column) => Schema::hasColumn('attendees', $column),
        ));

        if ($columns !== []) {
            Schema::table('attendees', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }

    private function addColumn(string $table, string $column, Closure $definition): void
    {
        if (! Schema::hasColumn($table, $column)) {
            Schema::table($table, $definition);
        }
    }

    /**
     * Drops a foreign key column together with its foreign key and its
     * unique(column, event_session_id) index, in that order.
     */
    private function dropColumnWithKeys(string $table, string $column, string $unique): void
    {
        if ($this->hasForeignKey($table, $column)) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign([$column]));
        }

        if (Schema::hasIndex($table, $unique)) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($unique));
        }

        // Later migrations may index the column too (SQLite cannot drop an indexed column).
        foreach (Schema::getIndexes($table) as $index) {
            if (! $index['primary'] && in_array($column, $index['columns'], true)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($index['name']));
            }
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($column));
    }

    /**
     * Makes a remapped column required and adds its unique(column,
     * event_session_id) index, then its foreign key, which on MySQL uses
     * that index.
     */
    private function finishKeyColumn(string $table, string $column, string $references, string $unique): void
    {
        if ($this->isNullable($table, $column)) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unsignedBigInteger($column)->nullable(false)->change());
        }

        if (! Schema::hasIndex($table, $unique)) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique([$column, 'event_session_id']));
        }

        if (! $this->hasForeignKey($table, $column)) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign($column)->references('id')->on($references)->cascadeOnDelete());
        }
    }

    private function hasForeignKey(string $table, string $column): bool
    {
        return collect(Schema::getForeignKeys($table))->contains(fn (array $foreignKey) => $foreignKey['columns'] === [$column]);
    }

    private function isNullable(string $table, string $column): bool
    {
        return (bool) collect(Schema::getColumns($table))->firstWhere('name', $column)['nullable'];
    }
};
