<?php

namespace Tests\Unit;

use App\Jobs\GenerateIdCardGridDownloadJob;
use App\Jobs\GenerateIdCardGridDownloadJobV2;
use App\Models\IdCardGridDownload;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class IdCardGridDownloadStatusTest extends TestCase
{
    public function test_both_jobs_retry_without_progress_when_column_is_missing(): void
    {
        foreach ([GenerateIdCardGridDownloadJob::class, GenerateIdCardGridDownloadJobV2::class] as $jobClass) {
            $download = \Mockery::mock(IdCardGridDownload::class);
            $download->shouldReceive('update')
                ->once()
                ->with(['status' => 'processing', 'progress_percentage' => 0])
                ->andThrow(new RuntimeException('Unknown column progress_percentage'));
            $download->shouldReceive('update')
                ->once()
                ->with(['status' => 'processing'])
                ->andReturn(true);

            $this->invokeStatusUpdate($jobClass, $download, [
                'status' => 'processing',
                'progress_percentage' => 0,
            ]);
        }
    }

    public function test_both_jobs_rethrow_unrelated_update_errors(): void
    {
        foreach ([GenerateIdCardGridDownloadJob::class, GenerateIdCardGridDownloadJobV2::class] as $jobClass) {
            $download = \Mockery::mock(IdCardGridDownload::class);
            $failure = new RuntimeException('Database unavailable');
            $download->shouldReceive('update')
                ->once()
                ->with(['status' => 'processing'])
                ->andThrow($failure);

            try {
                $this->invokeStatusUpdate($jobClass, $download, ['status' => 'processing']);
                $this->fail('Expected the original update error to be rethrown.');
            } catch (RuntimeException $exception) {
                $this->assertSame($failure, $exception);
            }
        }
    }

    /** @param class-string $jobClass */
    private function invokeStatusUpdate(string $jobClass, IdCardGridDownload $download, array $data): void
    {
        $job = new $jobClass($download);
        $method = new ReflectionMethod($jobClass, 'updateDownloadStatus');
        $method->invoke($job, $data);
    }
}
