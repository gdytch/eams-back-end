<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendee_duplicate_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendee_one_id')->constrained('attendees')->cascadeOnDelete();
            $table->foreignId('attendee_two_id')->constrained('attendees')->cascadeOnDelete();
            $table->char('name_fingerprint', 64);
            $table->foreignId('dismissed_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('dismissed_at');
            $table->timestamps();

            $table->unique(
                ['organization_id', 'attendee_one_id', 'attendee_two_id', 'name_fingerprint'],
                'attendee_duplicate_dismissals_unique_pair',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendee_duplicate_dismissals');
    }
};
