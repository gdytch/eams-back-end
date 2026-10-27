<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turns a pasted Google Fonts link into a cached regular-weight TTF. The pasted URL is only parsed for its
 * family name; every request goes to Google's own endpoints built here from that validated name.
 */
class GoogleFontService
{
    private const HOSTS = ['fonts.googleapis.com', 'fonts.google.com'];

    private const MAX_BYTES = 10 * 1024 * 1024;

    /** TrueType, OpenType/CFF and TrueType collection signatures. */
    private const SIGNATURES = ["\x00\x01\x00\x00", 'true', 'OTTO', 'ttcf'];

    /** Returns the family name in the link, or null when it is not a Google Fonts link with a valid family. */
    public function familyFromLink(string $link): ?string
    {
        $parts = parse_url(trim($link));
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || ! in_array(strtolower($parts['host'] ?? ''), self::HOSTS, true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }

        $raw = strtolower($parts['host']) === 'fonts.google.com'
            ? $this->specimenFamily($parts['path'] ?? '')
            : $this->queryFamily($parts['query'] ?? '');

        if ($raw === null) {
            return null;
        }
        $family = trim(preg_replace('/\s+/', ' ', $raw));

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9 ]{0,59}$/', $family) === 1 ? $family : null;
    }

    /**
     * Cached path of the family's regular-weight TTF on the local disk, downloading it from Google if needed.
     *
     * @throws GoogleFontException
     */
    public function cachedFont(string $family): string
    {
        $path = 'fonts/google/'.Str::slug($family).'.ttf';
        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            $disk->put($path, $this->download($this->fileUrl($family)));
        }

        return $path;
    }

    private function specimenFamily(string $path): ?string
    {
        $segments = explode('/', trim($path, '/'));

        return ($segments[0] ?? '') === 'specimen' && isset($segments[1])
            ? urldecode(str_replace('+', ' ', $segments[1]))
            : null;
    }

    private function queryFamily(string $query): ?string
    {
        foreach (explode('&', $query) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if ($key === 'family') {
                // Either "Name:wght@400;700" (css2) or "Name:400,700|Other" (css); only the first name counts.
                return preg_split('/[:|]/', urldecode(str_replace('+', ' ', $value)))[0];
            }
        }

        return null;
    }

    /** @throws GoogleFontException */
    private function fileUrl(string $family): string
    {
        $css = $this->get('https://fonts.googleapis.com/css2?'.http_build_query(['family' => $family]));
        if ($css?->clientError()) {
            throw new GoogleFontException("Font family \"$family\" was not found on Google Fonts.");
        }
        if (! $css?->successful()) {
            throw new GoogleFontException('Could not reach Google Fonts. Try again later.');
        }
        if (! preg_match('#url\((https://fonts\.gstatic\.com/[^)\s\'"]+)\)#', $css->body(), $match)) {
            throw new GoogleFontException("Font family \"$family\" has no downloadable regular-weight font file.");
        }

        return $match[1];
    }

    /** @throws GoogleFontException */
    private function download(string $url): string
    {
        $response = $this->get($url);
        $body = $response?->successful() ? $response->body() : '';
        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            throw new GoogleFontException('Could not download the font from Google Fonts. Try again later.');
        }
        if (! in_array(substr($body, 0, 4), self::SIGNATURES, true)) {
            throw new GoogleFontException('Google Fonts returned a file that is not a usable font.');
        }

        return $body;
    }

    private function get(string $url): ?Response
    {
        try {
            return Http::timeout(15)->withOptions(['allow_redirects' => false])->get($url);
        } catch (ConnectionException) {
            return null;
        }
    }
}
