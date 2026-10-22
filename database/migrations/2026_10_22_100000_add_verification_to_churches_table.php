<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('churches', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            // Identity is normalized name plus address, checked in the app.
            // MySQL needs an index on the mission_id foreign key once the unique one goes.
            $table->index('mission_id');
            $table->dropUnique(['mission_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('churches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn('verified_at');
            // Fails if two churches now share a name in one mission; merge them first.
            $table->unique(['mission_id', 'name']);
            $table->dropIndex(['mission_id']);
        });
    }
};
