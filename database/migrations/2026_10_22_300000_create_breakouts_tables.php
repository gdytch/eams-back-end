<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('breakouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('room');
            $table->unsignedInteger('capacity')->nullable();
            $table->json('topics');
            $table->timestamps();

            $table->index(['event_id', 'date', 'start_time']);
        });

        Schema::create('breakout_speaker', function (Blueprint $table) {
            $table->foreignId('breakout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('speaker_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);

            $table->primary(['breakout_id', 'speaker_id']);
        });

        Schema::create('breakout_picks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('breakout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendee_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['breakout_id', 'attendee_id']);
        });

        // The event program stays one per event; breakout programs share the table.
        Schema::table('event_programs', function (Blueprint $table) {
            $table->index('event_id');
            $table->foreignId('breakout_id')->nullable()->unique()->constrained()->cascadeOnDelete();
        });
        Schema::table('event_programs', fn (Blueprint $table) => $table->dropUnique(['event_id']));
    }

    public function down(): void
    {
        DB::table('event_programs')->whereNotNull('breakout_id')->delete();

        Schema::table('event_programs', function (Blueprint $table) {
            $table->dropForeign(['breakout_id']);
            $table->dropUnique(['breakout_id']);
            $table->dropColumn('breakout_id');
            $table->unique('event_id');
        });
        Schema::table('event_programs', fn (Blueprint $table) => $table->dropIndex(['event_id']));

        Schema::dropIfExists('breakout_picks');
        Schema::dropIfExists('breakout_speaker');
        Schema::dropIfExists('breakouts');
    }
};
