<?php

namespace App\Jobs\Concerns;

use App\Models\IdCardGridDownload;
use Exception;

/** @property IdCardGridDownload $download */
trait UpdatesIdCardGridDownloadStatus
{
    private function updateDownloadStatus(array $data): void
    {
        try {
            $this->download->update($data);
        } catch (Exception $e) {
            if (! str_contains($e->getMessage(), 'progress_percentage')) {
                throw $e;
            }

            unset($data['progress_percentage']);
            $this->download->update($data);
        }
    }
}
