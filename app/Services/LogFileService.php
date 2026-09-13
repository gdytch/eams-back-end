<?php

namespace App\Services;

use InvalidArgumentException;

class LogFileService
{
    private const MAX_BYTES = 100 * 1024 * 1024; // 100MB cap per read

    /**
     * Get the logs directory path.
     */
    public function directory(): string
    {
        return storage_path('logs');
    }

    /**
     * List available log dates in reverse chronological order (newest first).
     *
     * @return array<array{date: string, size: int, modified_at: int}>
     */
    public function availableDates(): array
    {
        $pattern = $this->directory().'/laravel-*.log';
        $files = glob($pattern) ?: [];

        $dates = [];
        foreach ($files as $file) {
            if (preg_match('#laravel-(\d{4}-\d{2}-\d{2})\.log$#', $file, $m)) {
                $dates[] = [
                    'date' => $m[1],
                    'size' => filesize($file) ?: 0,
                    'modified_at' => filemtime($file) ?: 0,
                ];
            }
        }

        // Sort newest first
        usort($dates, fn ($a, $b) => $b['modified_at'] <=> $a['modified_at']);

        return $dates;
    }

    /**
     * Get log entries for a given date, filtered by level and search keyword.
     *
     * @param  string  $date  Format: YYYY-MM-DD
     * @param  string|null  $level  Optional log level filter (e.g., 'error', 'warning')
     * @param  string|null  $search  Optional keyword search in raw entry text
     * @return array<array{timestamp: string, channel: string, level: string, message: string, raw: string}>
     */
    public function entriesForDate(string $date, ?string $level = null, ?string $search = null): array
    {
        if (! preg_match('#^\d{4}-\d{2}-\d{2}$#', $date)) {
            throw new InvalidArgumentException("Date must be in YYYY-MM-DD format: {$date}");
        }

        $file = $this->directory()."/laravel-{$date}.log";
        $realpath = realpath($file);

        // Path traversal check: ensure realpath is within logs directory
        if ($realpath === false || strpos($realpath, realpath($this->directory())) !== 0) {
            return [];
        }

        if (! file_exists($file)) {
            return [];
        }

        $content = $this->readFileContent($file);
        if ($content === '') {
            return [];
        }

        $entries = $this->parseEntries($content);

        // Apply filters
        if ($level !== null) {
            $entries = array_filter(
                $entries,
                fn ($entry) => strtolower($entry['level']) === strtolower($level)
            );
        }

        if ($search !== null) {
            $search = strtolower($search);
            $entries = array_filter(
                $entries,
                fn ($entry) => str_contains(strtolower($entry['raw']), $search)
            );
        }

        // Return newest first (reverse the order from file)
        return array_reverse($entries);
    }

    /**
     * Read file content, capped at MAX_BYTES (from tail if larger).
     */
    private function readFileContent(string $file): string
    {
        $filesize = filesize($file) ?: 0;

        if ($filesize === 0) {
            return '';
        }

        if ($filesize <= self::MAX_BYTES) {
            return file_get_contents($file) ?: '';
        }

        // Read only last MAX_BYTES
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return '';
        }

        fseek($handle, -self::MAX_BYTES, SEEK_END);
        $content = fread($handle, self::MAX_BYTES) ?: '';
        fclose($handle);

        // Discard everything before the first complete entry (avoid partial leading entry)
        if (preg_match('#^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]#m', $content)) {
            $content = preg_replace('#^.*?(?=\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\])/s', '', $content, 1);
        }

        return $content;
    }

    /**
     * Parse raw log content into structured entries.
     *
     * @return array<array{timestamp: string, channel: string, level: string, message: string, raw: string}>
     */
    private function parseEntries(string $content): array
    {
        $entries = [];

        // Split by entry header: [YYYY-MM-DD HH:MM:SS] channel.level: message
        if (! preg_match_all(
            '#^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\w+)\.(\w+): (.*)$#m',
            $content,
            $headerMatches,
            PREG_OFFSET_CAPTURE
        )) {
            return [];
        }

        $count = count($headerMatches[0]);

        for ($i = 0; $i < $count; $i++) {
            $timestamp = $headerMatches[1][$i][0];
            $channel = $headerMatches[2][$i][0];
            $level = $headerMatches[3][$i][0];
            $messageLine = $headerMatches[4][$i][0];
            $entryStart = $headerMatches[0][$i][1];

            // Find the raw entry content: from this header until the next header or EOF
            if ($i + 1 < $count) {
                $nextHeaderStart = $headerMatches[0][$i + 1][1];
                $raw = substr($content, $entryStart, $nextHeaderStart - $entryStart);
                $raw = rtrim($raw, "\n");
            } else {
                $raw = substr($content, $entryStart);
                $raw = rtrim($raw, "\n");
            }

            $entries[] = [
                'timestamp' => $timestamp,
                'channel' => $channel,
                'level' => $level,
                'message' => $messageLine,
                'raw' => $raw,
            ];
        }

        return $entries;
    }
}
