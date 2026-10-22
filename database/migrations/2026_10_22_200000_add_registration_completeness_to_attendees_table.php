<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendees', function (Blueprint $table) {
            $table->timestamp('registration_confirmed_at')->nullable()->after('registered_at');
            $table->timestamp('registration_completed_at')->nullable()->after('registration_confirmed_at');
            $table->timestamp('first_registered_at')->nullable()->after('registration_completed_at');
        });

        // Everyone registered before this release counted as finished.
        DB::table('attendees')->whereNotNull('registered_at')->update([
            'registration_confirmed_at' => DB::raw('registered_at'),
            'registration_completed_at' => DB::raw('registered_at'),
            'first_registered_at' => DB::raw('registered_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('attendees', function (Blueprint $table) {
            $table->dropColumn(['registration_confirmed_at', 'registration_completed_at', 'first_registered_at']);
        });
    }
};
