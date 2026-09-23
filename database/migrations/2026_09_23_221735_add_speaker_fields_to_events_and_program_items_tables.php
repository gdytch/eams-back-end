<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('speakers_public')->default(false)->after('banner_paths');
        });

        Schema::table('event_program_items', function (Blueprint $table) {
            $table->foreignId('speaker_id')->nullable()->after('attendee_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_program_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('speaker_id');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('speakers_public');
        });
    }
};
