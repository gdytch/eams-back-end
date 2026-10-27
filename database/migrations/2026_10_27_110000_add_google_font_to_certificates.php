<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            // The Google Fonts family chosen for the name and where its regular-weight TTF is cached.
            $table->string('google_font_family')->nullable()->after('font');
            $table->string('google_font_path')->nullable()->after('google_font_family');
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn(['google_font_family', 'google_font_path']);
        });
    }
};
