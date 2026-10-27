<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            // Not a design field: stays editable while published.
            $table->boolean('email_enabled')->default(false);
        });

        // One row per day: every message sent through the app counts toward the provider's daily limit.
        Schema::create('daily_mail_counts', function (Blueprint $table) {
            $table->date('day')->primary();
            $table->unsignedInteger('sent')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_mail_counts');
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn('email_enabled');
        });
    }
};
