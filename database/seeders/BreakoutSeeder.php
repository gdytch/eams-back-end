<?php

namespace Database\Seeders;

use App\Models\Breakout;
use App\Models\Event;
use Illuminate\Database\Seeder;

class BreakoutSeeder extends Seeder
{
    /** Two back-to-back slots on the first day of every event, three parallel breakouts each. */
    private const SLOTS = [
        ['start_time' => '09:00', 'end_time' => '10:30', 'breakouts' => [
            ['title' => 'Storytelling for Mission', 'room' => 'Room A', 'topics' => ['Storytelling', 'Writing']],
            ['title' => 'Photography Essentials', 'room' => 'Room B', 'topics' => ['Photography', 'Composition']],
            ['title' => 'Social Media Strategy', 'room' => 'Room C', 'topics' => ['Social media', 'Planning']],
        ]],
        ['start_time' => '10:45', 'end_time' => '12:15', 'breakouts' => [
            ['title' => 'Video Production Basics', 'room' => 'Room A', 'topics' => ['Video', 'Editing']],
            ['title' => 'Livestream Workshop', 'room' => 'Room B', 'topics' => ['Livestream', 'Audio']],
            ['title' => 'Church Newsletter Design', 'room' => 'Room C', 'topics' => ['Design', 'Print']],
        ]],
    ];

    public function run(): void
    {
        foreach (Event::withoutGlobalScopes()->get() as $event) {
            if ($event->breakouts()->withoutGlobalScopes()->exists()) {
                continue;
            }

            $speakers = $event->speakers()->get()->values();

            foreach (self::SLOTS as $slotIndex => $slot) {
                foreach ($slot['breakouts'] as $index => $data) {
                    $breakout = Breakout::query()->create([
                        'organization_id' => $event->organization_id,
                        'event_id' => $event->id,
                        'title' => $data['title'],
                        'description' => "Hands-on session: {$data['title']}.",
                        'date' => $event->start_date,
                        'start_time' => $slot['start_time'],
                        'end_time' => $slot['end_time'],
                        'room' => $data['room'],
                        // Last breakout of each slot stays uncapped.
                        'capacity' => $index === 2 ? null : 200,
                        'topics' => $data['topics'],
                    ]);

                    if ($speakers->isNotEmpty()) {
                        $breakout->speakers()->attach($speakers[($slotIndex * 3 + $index) % $speakers->count()]->id, ['sort_order' => 0]);
                    }
                }
            }

            // Everyone picks one breakout per slot.
            $event->forceFill(['pick_mode' => 'multiple', 'pick_min' => 2, 'pick_max' => 2])->save();
        }
    }
}
