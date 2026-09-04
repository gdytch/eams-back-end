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
        Schema::table('attendees', function (Blueprint $table) {
            $table->foreignId('church_id')->nullable()->after('mission_id')->constrained()->nullOnDelete();
            $table->string('mobile_no', 20)->nullable();
            $table->string('email_address')->nullable();
            $table->text('remarks')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('church_id');
            $table->dropColumn(['mobile_no', 'email_address', 'remarks']);
        });
    }
};
