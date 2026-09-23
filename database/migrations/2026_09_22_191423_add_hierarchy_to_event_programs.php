<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_programs', function (Blueprint $table) {
            $table->string('title')->nullable();
            $table->text('description')->nullable();
        });
        Schema::create('event_program_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_program_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('title')->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();
            $table->unique(['event_program_id', 'date']);
        });
        Schema::create('event_program_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_program_day_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();
        });
        Schema::table('event_program_items', function (Blueprint $table) {
            $table->foreignId('event_program_section_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('attendee_id')->nullable()->constrained()->nullOnDelete();
            $table->index('order');
        });
    }

    public function down(): void
    {
        Schema::table('event_program_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_program_section_id');
            $table->dropConstrainedForeignId('attendee_id');
            $table->dropIndex(['order']);
        });
        Schema::dropIfExists('event_program_sections');
        Schema::dropIfExists('event_program_days');
        Schema::table('event_programs', function (Blueprint $table) {
            $table->dropColumn(['title', 'description']);
        });
    }
};
