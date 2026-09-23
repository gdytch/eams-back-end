<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\EventProgram;
use Illuminate\Database\Seeder;

class EventProgramSeeder extends Seeder
{
    public function run(): void
    {
        $event = Event::query()->where('name', 'Annual Convention 2027')->first();

        if (! $event) {
            return;
        }

        $program = EventProgram::query()->firstOrCreate(
            ['event_id' => $event->id],
            [
                'title' => 'Annual Convention 2027 Program',
                'description' => 'Communication for Mission',
                'is_public' => true,
            ],
        );
        $speakers = $event->speakers()->get()->keyBy('designation');
        // These aliases identify the sample schedule roles. Displayed participant
        // names come from the Faker-generated speaker records below.
        $speakerSlots = collect([
            'Pr. Carlito Quidet Jr.' => 'Director, SSD Communication',
            'Rhoen Catolico' => 'Director, SePUM Communication/Media',
            'Edward Rodriguez' => 'Assistant Communication Director, SSD Communication - News and Media',
            'Anthony Stanyer' => 'Assistant Communication Director, SSD Communication - Social Media',
            'Pr. Jaffet Legario' => 'Communication Director',
        ])->mapWithKeys(fn(string $designation, string $sampleName) => [
            $sampleName => $speakers->get($designation),
        ]);

        $days = [
            [
                'date' => '2027-06-25',
                'title' => 'Thursday',
                'sections' => [
                    [
                        'title' => 'Arrival, Registration, and Dinner',
                        'start_time' => '16:00',
                        'end_time' => '18:30',
                    ],
                    [
                        'title' => 'Opening Program',
                        'start_time' => '19:00',
                        'end_time' => '20:30',
                        'items' => [
                            ['title' => 'Singspiration', 'participant' => 'NDM Team'],
                            ['title' => 'Opening Song', 'participant' => '“Communication for Mission”'],
                            ['title' => 'Opening Prayer', 'participant' => 'Pr. Jaffet Legario'],
                            ['title' => 'Welcome Remarks', 'participant' => 'Pr. Regie Mahinay'],
                            ['title' => 'Welcome, Recognition of Attendees, and Intro to Speaker', 'participant' => 'Rhoen Catolico'],
                            ['title' => 'Special Music', 'participant' => 'NDM Media'],
                            [
                                'title' => 'Speaker',
                                'participant' => 'Pr. Carlito Quidet Jr.',
                                'description' => 'Director, SSD Communication',
                                'details' => 'Biblical Foundation of Communication for Mission in the Old and New Testaments',
                            ],
                            ['title' => 'Closing Song', 'participant' => '“Communication for Mission”'],
                            ['title' => 'Closing Prayer', 'participant' => 'Ruthiton Rubino'],
                            ['title' => 'Announcements', 'participant' => 'Rhoen Catolico'],
                            ['title' => 'Program Coordinator', 'participant' => 'Florante Callo'],
                        ],
                    ],
                ],
            ],
            [
                'date' => '2027-06-26',
                'title' => 'Friday',
                'sections' => [
                    ['title' => 'Breakfast', 'start_time' => '06:00', 'end_time' => '07:30'],
                    [
                        'title' => 'Morning Devotional',
                        'start_time' => '08:00',
                        'end_time' => '09:25',
                        'items' => [
                            ['title' => 'Singspiration', 'participant' => 'Song Leader'],
                            ['title' => 'Opening Song', 'participant' => '“Communication for Mission”'],
                            ['title' => 'Opening Prayer', 'participant' => 'Pr. Jesreel Mercader'],
                            ['title' => 'Special Music', 'participant' => 'SSD-CSM Media Teams'],
                            ['title' => 'Welcome and Intro to Speaker', 'participant' => 'Ivan Mae Acob-Flores'],
                            [
                                'title' => 'Presenter',
                                'participant' => 'Rhoen Catolico',
                                'description' => 'Director, SePUM Communication/Media',
                                'details' => 'The Role of Communication in the Early Christian Church to the Adventist Movement',
                            ],
                            ['title' => 'Closing Song', 'participant' => '“Communication for Mission”'],
                            ['title' => 'Closing Prayer', 'participant' => 'Joris Bong Lagra'],
                            ['title' => 'Song Leader', 'participant' => 'Geraldine Ba-al'],
                            ['title' => 'Program Coordinator', 'participant' => 'Florante Callo'],
                        ],
                    ],
                    [
                        'title' => 'Importance of Communication, News, and Media',
                        'start_time' => '09:30',
                        'end_time' => '10:10',
                        'items' => [['title' => 'Presenter', 'participant' => 'Edward Rodriguez', 'description' => 'Assistant Communication Director, SSD Communication']],
                    ],
                    [
                        'title' => 'DSM: Social Media with Impact',
                        'start_time' => '10:15',
                        'end_time' => '10:50',
                        'items' => [['title' => 'Presenter', 'participant' => 'Anthony Stanyer', 'description' => 'Assistant Communication Director, SSD Communication']],
                    ],
                    [
                        'title' => 'Missional Facebook Posting',
                        'start_time' => '10:55',
                        'end_time' => '11:35',
                        'items' => [['title' => 'Presenter', 'participant' => 'Edward Rodriguez', 'description' => 'Assistant Communication Director, SSD Communication']],
                    ],
                    ['title' => 'Closing Prayer and Prayer for the Food', 'items' => [['participant' => 'Pr. Jaffet Legario']]],
                    ['title' => 'Lunch', 'start_time' => '11:45', 'end_time' => '13:00'],
                    ['title' => 'Opening Prayer', 'items' => [['participant' => 'June Clyde Murillo']]],
                    [
                        'title' => 'Sabbath Announcements and News Podcast',
                        'start_time' => '13:30',
                        'end_time' => '14:10',
                        'items' => [['participant' => 'Rhoen Catolico', 'description' => 'Director, SePUM Communication/Media']],
                    ],
                    [
                        'title' => 'Spokesperson and Crisis Management and Church Bulletin Boards, and Promotions',
                        'start_time' => '14:15',
                        'end_time' => '14:55',
                        'items' => [['participant' => 'Carlito Quidet, Jr.', 'description' => 'Director, SSD Communication/Media']],
                    ],
                    [
                        'title' => 'Adventist Identity Guidelines',
                        'start_time' => '15:00',
                        'end_time' => '15:40',
                        'items' => [['participant' => 'Anthony Stanyer', 'description' => 'Assistant Communication Director, SSD Communication']],
                    ],
                    [
                        'title' => 'Visual Language, Audiovisual Production, and the Concept of Connectivity in Strategic Communication',
                        'start_time' => '15:45',
                        'end_time' => '16:25',
                        'items' => [['participant' => 'Edward Rodriguez', 'description' => 'Assistant Communication Director, SSD Communication']],
                    ],
                    [
                        'title' => 'Church Social Media Management',
                        'start_time' => '16:30',
                        'end_time' => '17:10',
                        'items' => [['participant' => 'Anthony Stanyer', 'description' => 'Assistant Communication Director, SSD Communication']],
                    ],
                    ['title' => 'Closing Prayer', 'items' => [['participant' => 'Pr. Elmer Romano']]],
                    ['title' => 'Dinner', 'start_time' => '18:00', 'end_time' => '18:45'],
                ],
            ],
        ];

        foreach ($days as $dayOrder => $dayData) {
            $day = $program->days()->firstOrCreate(
                ['date' => $dayData['date']],
                ['title' => $dayData['title'], 'sort_order' => $dayOrder],
            );

            foreach ($dayData['sections'] as $sectionOrder => $sectionData) {
                $items = $sectionData['items'] ?? [];
                unset($sectionData['items']);
                $section = $day->sections()->create([...$sectionData, 'sort_order' => $sectionOrder]);

                foreach ($items as $itemOrder => $itemData) {
                    $sampleParticipant = $itemData['participant'] ?? null;
                    $linkedSpeaker = $speakerSlots->get($sampleParticipant);

                    $section->items()->create([
                        'event_program_id' => $program->id,
                        'date' => $day->date,
                        'order' => $itemOrder,
                        'speaker_id' => $linkedSpeaker?->id,
                        'part_title' => $itemData['title'] ?? null,
                        'participant_name' => $linkedSpeaker?->name ?? $sampleParticipant,
                        'participant_description' => $itemData['description'] ?? null,
                        'part_description' => $itemData['details'] ?? null,
                    ]);
                }
            }
        }
    }
}
