<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Services\ImageUploadService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

class SpeakerSeeder extends Seeder
{
    public function run(): void
    {
        $event = Event::query()->where('name', 'Annual Convention 2027')->first();

        if (! $event) {
            return;
        }

        $event->update(['speakers_public' => true]);

        $speakers = [
            ['designation' => 'Director, SSD Communication', 'organization' => 'Southern Asia-Pacific Division', 'avatar' => 'avatar1.png'],
            ['designation' => 'Director, SePUM Communication/Media', 'organization' => 'Southeastern Philippine Union Mission', 'avatar' => 'avatar2.png'],
            ['designation' => 'Assistant Communication Director, SSD Communication - News and Media', 'organization' => 'Southern Asia-Pacific Division', 'avatar' => 'avatar9.png'],
            ['designation' => 'Assistant Communication Director, SSD Communication - Social Media', 'organization' => 'Southern Asia-Pacific Division', 'avatar' => 'avatar11.png'],
            ['designation' => 'Communication Director', 'organization' => 'Southeastern Philippine Union Mission', 'avatar' => 'avatar19.png'],
        ];

        $imageUploadService = new ImageUploadService;

        foreach ($speakers as $speakerData) {
            $avatarPath = storage_path("sample-avatars/png/{$speakerData['avatar']}");
            $speaker = $event->speakers()->updateOrCreate(
                ['designation' => $speakerData['designation']],
                [
                    'name' => fake()->name(),
                    'designation' => $speakerData['designation'],
                    'organization' => $speakerData['organization'],
                    'bio' => "This speaker serves in {$speakerData['designation']} at {$speakerData['organization']}.",
                ],
            );

            if (is_file($avatarPath)) {
                $speaker->update([
                    'photo_paths' => $imageUploadService->process(
                        new UploadedFile($avatarPath, $speakerData['avatar'], 'image/png', null, true),
                        preset: 'profile_photo',
                        directory: "speakers/{$speaker->id}",
                        prefix: 'photo',
                    ),
                ]);
            }
        }
    }
}
