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
        Schema::table('event_registrations', function (Blueprint $table) {
            $table->string('qr_token', 512)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Truncate tokens longer than 64 characters before resizing
        \DB::table('event_registrations')
            ->whereRaw('LENGTH(qr_token) > 64')
            ->update(['qr_token' => \DB::raw('LEFT(qr_token, 64)')]);

        Schema::table('event_registrations', function (Blueprint $table) {
            $table->string('qr_token', 64)->change();
        });
    }
};
