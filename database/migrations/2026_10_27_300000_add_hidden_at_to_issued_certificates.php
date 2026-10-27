<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issued_certificates', function (Blueprint $table) {
            // Set when the Attendee leaves the Audience: the file is kept but hidden from lists, counts and downloads.
            $table->timestamp('hidden_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('issued_certificates', function (Blueprint $table) {
            $table->dropColumn('hidden_at');
        });
    }
};
