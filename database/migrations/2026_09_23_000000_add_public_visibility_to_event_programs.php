<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_programs', function (Blueprint $table) {
            $table->boolean('is_public')->default(false)->after('description');
            $table->string('public_slug')->nullable()->unique()->after('is_public');
        });
    }

    public function down(): void
    {
        Schema::table('event_programs', function (Blueprint $table) {
            $table->dropUnique(['public_slug']);
            $table->dropColumn(['is_public', 'public_slug']);
        });
    }
};
