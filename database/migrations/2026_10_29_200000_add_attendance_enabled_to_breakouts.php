<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The default backfills every existing breakout to true, so current events behave as before.
        Schema::table('breakouts', function (Blueprint $table) {
            $table->boolean('attendance_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('breakouts', function (Blueprint $table) {
            $table->dropColumn('attendance_enabled');
        });
    }
};
