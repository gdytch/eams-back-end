<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('template_path')->nullable();
            // Name position and size are fractions of the page so they survive page-size changes.
            $table->double('name_x')->default(0.5);
            $table->double('name_y')->default(0.5);
            $table->double('name_font_size')->default(0.05);
            $table->string('font')->default('sans-serif');
            $table->string('color', 7)->default('#000000');
            $table->string('page_size')->default('a4');
            $table->string('orientation')->default('landscape');
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
