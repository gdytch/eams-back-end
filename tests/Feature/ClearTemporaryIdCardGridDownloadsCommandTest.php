<?php

namespace Tests\Feature;

use App\Enums\IdCardGridDownloadStatus;
use App\Models\Event;
use App\Models\IdCardGridDownload;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClearTemporaryIdCardGridDownloadsCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_cleanup_removes_old_terminal_and_orphaned_files_but_keeps_active_files(): void
    {
        Storage::fake('local');
        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->create();
        $user = User::factory()->orgAdmin()->for($org)->create();
        $old = now()->subDays(8);
        $completed = IdCardGridDownload::factory()->for($event)->for($user, 'requestedBy')->create([
            'status' => IdCardGridDownloadStatus::Completed,
            'file_path' => "{$event->id}/id-card-grids/completed.zip",
            'completed_at' => $old,
            'created_at' => $old,
            'updated_at' => $old,
        ]);
        $pending = IdCardGridDownload::factory()->for($event)->for($user, 'requestedBy')->create([
            'status' => IdCardGridDownloadStatus::Pending,
            'file_path' => "{$event->id}/id-card-grids/pending.zip",
            'created_at' => $old,
            'updated_at' => now(),
        ]);
        $stale = IdCardGridDownload::factory()->for($event)->for($user, 'requestedBy')->create([
            'status' => IdCardGridDownloadStatus::Processing,
            'created_at' => $old,
            'updated_at' => $old,
        ]);
        $stale->update(['file_path' => "{$event->id}/id-card-grids/{$stale->id}/download.zip"]);
        DB::table('id_card_grid_downloads')->where('id', $stale->id)->update(['updated_at' => $old]);

        Storage::disk('local')->put($completed->file_path, 'completed');
        Storage::disk('local')->put($pending->file_path, 'active');
        Storage::disk('local')->put($stale->file_path, 'stale');
        $orphanPath = "{$event->id}/id-card-grids/999/batches/old.pdf";
        Storage::disk('local')->put($orphanPath, 'orphan');
        touch(Storage::disk('local')->path($orphanPath), now()->subDays(2)->timestamp);

        $this->artisan('app:clear-temporary-id-card-grid-downloads')->assertSuccessful();

        $this->assertDatabaseMissing('id_card_grid_downloads', ['id' => $completed->id]);
        $this->assertDatabaseHas('id_card_grid_downloads', ['id' => $pending->id]);
        $this->assertDatabaseHas('id_card_grid_downloads', ['id' => $stale->id, 'status' => IdCardGridDownloadStatus::Failed->value]);
        Storage::disk('local')->assertMissing($completed->file_path);
        Storage::disk('local')->assertExists($pending->file_path);
        Storage::disk('local')->assertMissing($stale->file_path);
        Storage::disk('local')->assertMissing("{$event->id}/id-card-grids/999/batches/old.pdf");
    }
}
