<?php

namespace App\Console\Commands;

use App\Enums\IdCardGridDownloadStatus;
use App\Models\IdCardGridDownload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

#[Signature('app:clear-temporary-id-card-grid-downloads')]
#[Description('Remove expired ID card grid downloads and orphaned temporary files')]
class ClearTemporaryIdCardGridDownloads extends Command
{
    private const RETAIN_DAYS = 7;

    private const ORPHAN_HOURS = 24;

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $expiredAt = now()->subDays(self::RETAIN_DAYS);
        $expired = IdCardGridDownload::query()
            ->whereIn('status', [IdCardGridDownloadStatus::Completed, IdCardGridDownloadStatus::Failed])
            ->whereRaw('COALESCE(completed_at, updated_at) < ?', [$expiredAt])
            ->get();

        foreach ($expired as $download) {
            Cache::lock("id-card-grid-download:{$download->id}", 600)->get(function () use ($disk, $download): void {
                $disk->deleteDirectory("{$download->event_id}/id-card-grids/{$download->id}");
                if ($download->file_path !== null) {
                    $disk->delete($download->file_path);
                }
                $download->delete();
            });
        }

        $staleBefore = now()->subHours(self::ORPHAN_HOURS);
        $stale = IdCardGridDownload::query()
            ->whereIn('status', [IdCardGridDownloadStatus::Pending, IdCardGridDownloadStatus::Processing])
            ->where('updated_at', '<', $staleBefore)
            ->get();

        foreach ($stale as $download) {
            // Compare-and-set against the cutoff: a queued job may have
            // claimed this row after the stale scan was read.
            $expired = IdCardGridDownload::query()
                ->whereKey($download->id)
                ->whereIn('status', [IdCardGridDownloadStatus::Pending, IdCardGridDownloadStatus::Processing])
                ->where('updated_at', '<', $staleBefore)
                ->update([
                    'status' => IdCardGridDownloadStatus::Failed,
                    'failure_reason' => 'Download expired before processing completed.',
                    'file_path' => null,
                ]);

            if ($expired === 0) {
                continue;
            }

            $disk->deleteDirectory("{$download->event_id}/id-card-grids/{$download->id}");
        }

        $tracked = IdCardGridDownload::query()->get(['event_id', 'id'])->mapWithKeys(
            fn (IdCardGridDownload $download) => ["{$download->event_id}:{$download->id}" => true]
        );
        $orphanBefore = now()->subHours(self::ORPHAN_HOURS)->timestamp;
        $checkedDirectories = [];

        foreach ($disk->allFiles() as $path) {
            if (! preg_match('~^(\d+)/id-card-grids/(\d+)/~', $path, $matches)) {
                continue;
            }

            $key = "{$matches[1]}:{$matches[2]}";
            if (isset($tracked[$key]) || isset($checkedDirectories[$key])) {
                continue;
            }

            $checkedDirectories[$key] = true;
            if ($disk->lastModified($path) < $orphanBefore) {
                $disk->deleteDirectory("{$matches[1]}/id-card-grids/{$matches[2]}");
            }
        }

        $this->info('Expired ID card grid downloads and orphan files cleared.');

        return self::SUCCESS;
    }
}
