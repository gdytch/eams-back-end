<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfilePhotoAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_photo_requires_valid_unexpired_signature_and_supports_legacy_storage(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $user = User::factory()->create();
        $path = "users/{$user->id}/photo-sm.webp";
        $user->update(['photo_paths' => ['sm' => $path]]);
        Storage::disk('public')->put($path, 'legacy-photo');
        $url = $user->photo_urls['sm'];

        $this->get($url)->assertOk();
        $this->get(strtok($url, '?'))->assertForbidden();
        $this->get(str_replace('/sm?', '/lg?', $url))->assertForbidden();
        $this->travel(31)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_private_photo_is_preferred_and_arbitrary_paths_are_never_served(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $user = User::factory()->create();
        $path = "users/{$user->id}/photo-original.webp";
        $user->update(['photo_paths' => ['original' => $path]]);
        Storage::disk('local')->put($path, 'private-photo');
        $this->get($user->photo_urls['original'])->assertOk();

        $user->update(['photo_paths' => ['original' => '../../.env']]);
        $this->get($user->photo_urls['original'])->assertNotFound();
    }
}
