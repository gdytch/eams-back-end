<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('breakout_picks', fn (Blueprint $table) => $table->boolean('overbooked')->default(false));
        Schema::table('attendees', function (Blueprint $table) {
            $table->timestamp('picks_saved_at')->nullable();
            $table->timestamp('picks_released_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendees', fn (Blueprint $table) => $table->dropColumn(['picks_saved_at', 'picks_released_at']));
        Schema::table('breakout_picks', fn (Blueprint $table) => $table->dropColumn('overbooked'));
    }
};
