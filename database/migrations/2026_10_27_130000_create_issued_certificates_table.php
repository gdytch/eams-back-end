<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            // Bumped on every publish so jobs left behind by an earlier run can tell they are stale.
            $table->unsignedInteger('generation_run')->default(0);
        });

        Schema::create('issued_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certificate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendee_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->string('failure_reason', 500)->nullable();
            $table->boolean('name_too_long')->default(false);
            // Private file on the local disk; the row outlives the file (see file_deleted_at).
            $table->string('file_path')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('file_deleted_at')->nullable();
            $table->timestamps();

            $table->unique(['certificate_id', 'attendee_id']);
            $table->index(['certificate_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_certificates');
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn('generation_run');
        });
    }
};
