<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHECK = 'attendance_records_one_slot_check';

    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->unsignedBigInteger('event_session_id')->nullable()->change();
            $table->foreignId('breakout_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('via_breakout_id')->nullable()->constrained('breakouts')->nullOnDelete();
            $table->boolean('is_walk_in')->default(false);
            $table->unique(['attendee_id', 'breakout_id']);
        });

        // SQLite cannot add a constraint to an existing table; the controller enforces the rule there.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE attendance_records ADD CONSTRAINT '.self::CHECK.' CHECK ((event_session_id IS NULL) <> (breakout_id IS NULL))');
        }
    }

    public function down(): void
    {
        // Breakout records have no session to fall back to, so they cannot survive the rollback.
        DB::table('attendance_records')->whereNull('event_session_id')->delete();

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE attendance_records DROP CONSTRAINT '.self::CHECK);
        }

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropUnique(['attendee_id', 'breakout_id']);
            $table->dropConstrainedForeignId('via_breakout_id');
            $table->dropConstrainedForeignId('breakout_id');
            $table->dropColumn('is_walk_in');
            $table->unsignedBigInteger('event_session_id')->nullable(false)->change();
        });
    }
};
