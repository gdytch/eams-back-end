<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            // Audience selection; all three empty means every Fully registered Attendee of the event.
            $table->json('audience_union_ids')->nullable();
            $table->json('audience_mission_ids')->nullable();
            $table->json('audience_attendee_ids')->nullable();
            $table->string('release_rule')->default('everyone');
            $table->boolean('include_breakouts')->default(false);
            $table->timestamp('release_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn([
                'audience_union_ids', 'audience_mission_ids', 'audience_attendee_ids',
                'release_rule', 'include_breakouts', 'release_at',
            ]);
        });
    }
};
