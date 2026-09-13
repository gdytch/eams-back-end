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
        Schema::table('id_card_grid_downloads', function (Blueprint $table) {
            $table->unsignedTinyInteger('progress_percentage')->default(0)->after('status');
        });
    }

    /**
     * Run the migrations.
     */
    public function down(): void
    {
        Schema::table('id_card_grid_downloads', function (Blueprint $table) {
            $table->dropColumn('progress_percentage');
        });
    }
};
