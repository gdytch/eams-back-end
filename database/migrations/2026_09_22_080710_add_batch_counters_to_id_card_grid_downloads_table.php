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
            $table->unsignedInteger('total_batches')
                ->default(0)
                ->after('progress_percentage');
            $table->unsignedInteger('completed_batches')
                ->default(0)
                ->after('total_batches');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('id_card_grid_downloads', function (Blueprint $table) {
            $table->dropColumn(['total_batches', 'completed_batches']);
        });
    }
};
