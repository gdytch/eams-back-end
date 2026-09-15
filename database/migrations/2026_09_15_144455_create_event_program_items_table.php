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
        Schema::create('event_program_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_program_id')->constrained()->cascadeOnDelete();
            $table->date('date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('part_title')->nullable();
            $table->string('part_subtitle')->nullable();
            $table->text('part_description')->nullable();
            $table->string('participant_name')->nullable();
            $table->text('participant_description')->nullable();
            $table->json('photo_paths')->nullable();
            $table->text('part_remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_program_items');
    }
};
