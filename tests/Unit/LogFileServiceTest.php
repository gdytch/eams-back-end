<?php

namespace Tests\Unit;

use App\Services\LogFileService;
use Tests\TestCase;

class LogFileServiceTest extends TestCase
{
    public function test_large_log_read_discards_partial_entry_and_keeps_complete_entries(): void
    {
        $originalStoragePath = app()->storagePath();
        $storagePath = sys_get_temp_dir().'/ams-large-log-'.bin2hex(random_bytes(6));
        mkdir($storagePath.'/logs', 0755, true);
        app()->useStoragePath($storagePath);
        $file = $storagePath.'/logs/laravel-2026-09-28.log';
        $tail = "partial entry\n[2026-09-28 10:00:00] laravel.info: first complete\n[2026-09-28 10:01:00] laravel.error: second complete\n";
        $maxBytes = 100 * 1024 * 1024;
        $fileSize = $maxBytes + strlen($tail);

        try {
            ini_set('memory_limit', '512M');
            $handle = fopen($file, 'w+');
            ftruncate($handle, $fileSize);
            fseek($handle, $maxBytes);
            fwrite($handle, $tail);
            fclose($handle);

            $entries = (new LogFileService)->entriesForDate('2026-09-28');

            $this->assertCount(2, $entries);
            $this->assertSame('second complete', $entries[0]['message']);
            $this->assertSame('first complete', $entries[1]['message']);
        } finally {
            unset($entries);
            app()->useStoragePath($originalStoragePath);
            @unlink($file);
            @rmdir($storagePath.'/logs');
            @rmdir($storagePath);
        }
    }
}
