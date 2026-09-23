<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendees', function (Blueprint $table) {
            $table->foreignId('merged_into_id')->nullable()->after('user_id')->constrained('attendees')->restrictOnDelete();
            $table->foreignId('merged_by')->nullable()->after('merged_into_id')->constrained('users')->nullOnDelete();
            $table->timestamp('merged_at')->nullable()->after('merged_by');
            $table->index(['organization_id', 'normalized_name', 'merged_at'], 'attendees_duplicate_lookup_index');
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            // MySQL may reuse composite lookup index as backing index for FK.
            // Check catalog first because DDL can partially commit on rollback.
            $schema = DB::getDatabaseName();
            $temporaryOrganizationIndex = 'attendees_rollback_org_index';
            $temporaryIndexExists = DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', $schema)
                ->where('TABLE_NAME', 'attendees')
                ->where('INDEX_NAME', $temporaryOrganizationIndex)
                ->exists();
            if (! $temporaryIndexExists) {
                DB::statement("ALTER TABLE `attendees` ADD INDEX `{$temporaryOrganizationIndex}` (`organization_id`)");
            }

            foreach (['attendees_merged_into_id_foreign', 'attendees_merged_by_foreign'] as $constraint) {
                $exists = DB::table('information_schema.TABLE_CONSTRAINTS')
                    ->where('CONSTRAINT_SCHEMA', $schema)
                    ->where('TABLE_NAME', 'attendees')
                    ->where('CONSTRAINT_NAME', $constraint)
                    ->exists();
                if ($exists) {
                    DB::statement("ALTER TABLE `attendees` DROP FOREIGN KEY `{$constraint}`");
                }
            }

            $indexExists = DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', $schema)
                ->where('TABLE_NAME', 'attendees')
                ->where('INDEX_NAME', 'attendees_duplicate_lookup_index')
                ->exists();
            if ($indexExists) {
                DB::statement('ALTER TABLE `attendees` DROP INDEX `attendees_duplicate_lookup_index`');
            }

            // Keep single-column organization index. Existing organization FK
            // needs it after duplicate lookup index is removed.
        }

        Schema::table('attendees', function (Blueprint $table) {
            if (DB::connection()->getDriverName() !== 'mysql') {
                $table->dropForeign(['merged_into_id']);
                $table->dropForeign(['merged_by']);
                $table->dropIndex('attendees_duplicate_lookup_index');
            }
            $columns = array_values(array_filter(
                ['merged_into_id', 'merged_by', 'merged_at'],
                fn (string $column) => Schema::hasColumn('attendees', $column),
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
