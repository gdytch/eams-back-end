<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // pick_mode null = breakouts disabled. Mode "one" stores min = max = 1.
        Schema::table('events', function (Blueprint $table) {
            $table->string('pick_mode')->nullable();
            $table->unsignedInteger('pick_min')->nullable();
            $table->unsignedInteger('pick_max')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn(['pick_mode', 'pick_min', 'pick_max']));
    }
};
