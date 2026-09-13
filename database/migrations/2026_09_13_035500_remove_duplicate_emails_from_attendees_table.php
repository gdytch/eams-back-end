<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Find emails that appear more than once
        $duplicates = DB::table('attendees')
            ->select('email_address', DB::raw('MIN(id) as keep_id'))
            ->whereNotNull('email_address')
            ->groupBy('email_address')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        // For each duplicate email, delete all but the first (oldest by id)
        foreach ($duplicates as $dup) {
            DB::table('attendees')
                ->where('email_address', $dup->email_address)
                ->where('id', '!=', $dup->keep_id)
                ->delete();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This data migration cannot be safely reversed
    }
};
