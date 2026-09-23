<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            DB::table('event_programs')->orderBy('id')->chunkById(100, function ($programs) {
                foreach ($programs as $program) {
                    $eventDate = DB::table('events')->where('id', $program->event_id)->value('start_date');
                    $groups = DB::table('event_program_items')->where('event_program_id', $program->id)
                        ->whereNull('event_program_section_id')->orderBy('date')->orderBy('order')->orderBy('id')->get()
                        ->groupBy(fn ($item) => $item->date ?: substr($eventDate ?: $program->created_at, 0, 10));
                    foreach ($groups->keys() as $order => $date) {
                        $day = DB::table('event_program_days')->insertGetId([
                            'event_program_id' => $program->id, 'date' => $date, 'sort_order' => $order,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        $section = DB::table('event_program_sections')->insertGetId([
                            'event_program_day_id' => $day, 'title' => 'Program', 'sort_order' => 0,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        DB::table('event_program_items')->whereIn('id', $groups[$date]->pluck('id'))
                            ->update(['event_program_section_id' => $section]);
                    }
                }
            });
        });
    }

    public function down(): void
    {
        // The schema rollback removes grouping; original item data remains intact.
    }
};
