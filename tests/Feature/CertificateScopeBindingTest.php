<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CertificateScopeBindingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Event $event;

    private Certificate $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $org = Organization::factory()->create();
        $this->event = Event::factory()->for($org)->create();
        $otherEvent = Event::factory()->for($org)->create();
        $this->foreign = Certificate::factory()->for($otherEvent)->create();
        $this->actingAs(User::factory()->orgAdmin()->for($org)->create(), 'sanctum');
    }

    public function test_template_upload_of_another_events_certificate_is_not_found(): void
    {
        $this->postJson("/api/v1/events/{$this->event->id}/certificates/{$this->foreign->id}/template", [
            'template' => UploadedFile::fake()->image('t.png', 600, 400),
        ])->assertNotFound();

        $this->assertNull($this->foreign->fresh()->template_path);
    }

    public function test_google_font_of_another_events_certificate_is_not_found(): void
    {
        $this->postJson("/api/v1/events/{$this->event->id}/certificates/{$this->foreign->id}/google-font", [
            'url' => 'https://fonts.google.com/specimen/Lobster',
        ])->assertNotFound();
    }
}
