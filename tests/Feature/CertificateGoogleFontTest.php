<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CertificateGoogleFontTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TTF_BYTES = "\x00\x01\x00\x00fake-font-data";

    private Organization $org;

    private Certificate $certificate;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->org = Organization::factory()->create();
        $event = Event::factory()->for($this->org)->create();
        $this->certificate = Certificate::factory()->for($event)->create();
        $this->url = "/api/v1/events/{$event->id}/certificates/{$this->certificate->id}/google-font";
        $this->actingAs(User::factory()->orgAdmin()->for($this->org)->create(), 'sanctum');
    }

    private function fakeGoogle(string $family = 'Open Sans'): void
    {
        $css = "@font-face {\n  font-family: '$family';\n  font-style: normal;\n  font-weight: 400;\n"
            ."  src: url(https://fonts.gstatic.com/s/x/v1/abc.ttf) format('truetype');\n}\n";

        Http::fake([
            'fonts.googleapis.com/css2?family=Nope*' => Http::response('Not found', 400),
            'fonts.googleapis.com/*' => Http::response($css),
            'fonts.gstatic.com/*' => Http::response(self::TTF_BYTES),
            '*' => Http::response('unexpected', 500),
        ]);
    }

    /** @return list<string> */
    private function requestedUrls(): array
    {
        return Http::recorded()->map(fn (array $pair) => $pair[0]->url())->values()->all();
    }

    public function test_a_valid_google_fonts_link_becomes_the_cached_font_of_the_certificate(): void
    {
        $this->fakeGoogle();

        $this->postJson($this->url, ['url' => 'https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;700&display=swap'])
            ->assertOk()
            ->assertJsonPath('data.font', 'google')
            ->assertJsonPath('data.google_font_family', 'Open Sans');

        $path = $this->certificate->refresh()->google_font_path;
        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
        $this->assertSame(self::TTF_BYTES, Storage::disk('local')->get($path));
        $this->assertDatabaseHas('audit_logs', ['action' => 'certificate.google_font_set', 'auditable_id' => $this->certificate->id]);
    }

    public function test_specimen_links_on_fonts_google_com_are_accepted(): void
    {
        $this->fakeGoogle('Lobster');

        $this->postJson($this->url, ['url' => 'https://fonts.google.com/specimen/Lobster?query=lob'])
            ->assertOk()->assertJsonPath('data.google_font_family', 'Lobster');
    }

    public function test_only_the_family_name_is_used_and_the_pasted_url_is_never_requested(): void
    {
        $this->fakeGoogle();
        $pasted = 'https://fonts.googleapis.com/css2?family=Open+Sans&display=swap&x=secret-token';

        $this->postJson($this->url, ['url' => $pasted])->assertOk();

        $urls = $this->requestedUrls();
        $this->assertNotContains($pasted, $urls);
        foreach ($urls as $requested) {
            $this->assertStringNotContainsString('secret-token', $requested);
            $this->assertMatchesRegularExpression('#^https://fonts\.(googleapis|gstatic)\.com/#', $requested);
        }
        $this->assertStringStartsWith('https://fonts.googleapis.com/css2?family=Open+Sans', $urls[0]);
    }

    public function test_a_font_already_cached_is_not_downloaded_again(): void
    {
        $this->fakeGoogle();
        $link = ['url' => 'https://fonts.google.com/specimen/Open+Sans'];

        $this->postJson($this->url, $link)->assertOk();
        $first = $this->certificate->refresh()->google_font_path;
        Http::fake(['*' => Http::response('unexpected', 500)]);
        $this->postJson($this->url, $link)->assertOk();

        $this->assertSame($first, $this->certificate->refresh()->google_font_path);
        Http::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function rejectedLinks(): array
    {
        return [
            'other host' => ['https://evil.example.com/css2?family=Open+Sans'],
            'look-alike host' => ['https://fonts.googleapis.com.evil.com/css2?family=Open+Sans'],
            'userinfo trick' => ['https://fonts.googleapis.com@evil.com/css2?family=Open+Sans'],
            'plain http' => ['http://fonts.googleapis.com/css2?family=Open+Sans'],
            'not a url' => ['Open Sans'],
            'internal address' => ['http://169.254.169.254/latest/meta-data'],
            'no family' => ['https://fonts.googleapis.com/css2?display=swap'],
            'bad characters' => ['https://fonts.googleapis.com/css2?family=Open%20Sans%27%3Bdrop'],
            'path traversal' => ['https://fonts.googleapis.com/css2?family=..%2F..%2Fetc'],
        ];
    }

    #[DataProvider('rejectedLinks')]
    public function test_links_that_are_not_google_fonts_are_rejected_without_any_request(string $link): void
    {
        $this->fakeGoogle();

        $this->postJson($this->url, ['url' => $link])
            ->assertUnprocessable()->assertJsonValidationErrors('url');

        Http::assertNothingSent();
        $this->assertUnchanged();
    }

    public function test_the_host_error_message_is_clear(): void
    {
        $response = $this->postJson($this->url, ['url' => 'https://example.com/x'])->assertUnprocessable();

        $this->assertStringContainsString('Google Fonts', $response->json('errors.url.0'));
    }

    public function test_an_unknown_family_gives_a_clear_error_and_leaves_the_certificate_unchanged(): void
    {
        Http::fake(['fonts.googleapis.com/*' => Http::response('Not found', 400)]);

        $response = $this->postJson($this->url, ['url' => 'https://fonts.google.com/specimen/Nope+Nope'])
            ->assertUnprocessable()->assertJsonValidationErrors('url');

        $this->assertStringContainsString('not found', strtolower($response->json('errors.url.0')));
        $this->assertUnchanged();
    }

    public function test_a_download_failure_gives_a_clear_error_and_leaves_the_certificate_unchanged(): void
    {
        Http::fake([
            'fonts.googleapis.com/*' => Http::response("@font-face { src: url(https://fonts.gstatic.com/s/x/a.ttf) format('truetype'); }"),
            'fonts.gstatic.com/*' => Http::response('', 503),
        ]);

        $this->postJson($this->url, ['url' => 'https://fonts.google.com/specimen/Lobster'])
            ->assertUnprocessable()->assertJsonValidationErrors('url');

        $this->assertUnchanged();
    }

    public function test_a_connection_failure_leaves_the_certificate_unchanged(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->postJson($this->url, ['url' => 'https://fonts.google.com/specimen/Lobster'])
            ->assertUnprocessable()->assertJsonValidationErrors('url');

        $this->assertUnchanged();
    }

    public function test_a_download_that_is_not_a_font_file_is_refused(): void
    {
        Http::fake([
            'fonts.googleapis.com/*' => Http::response("@font-face { src: url(https://fonts.gstatic.com/s/x/a.ttf) format('truetype'); }"),
            'fonts.gstatic.com/*' => Http::response('<html>not a font</html>'),
        ]);

        $this->postJson($this->url, ['url' => 'https://fonts.google.com/specimen/Lobster'])
            ->assertUnprocessable()->assertJsonValidationErrors('url');

        $this->assertUnchanged();
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_font_file_url_outside_google_static_is_never_requested(): void
    {
        Http::fake([
            'fonts.googleapis.com/*' => Http::response("@font-face { src: url(https://evil.example.com/a.ttf) format('truetype'); }"),
            '*' => Http::response(self::TTF_BYTES),
        ]);

        $this->postJson($this->url, ['url' => 'https://fonts.google.com/specimen/Lobster'])
            ->assertUnprocessable();

        $this->assertCount(1, $this->requestedUrls());
        $this->assertUnchanged();
    }

    public function test_a_failed_attempt_keeps_the_previously_chosen_google_font(): void
    {
        $this->fakeGoogle();
        $this->postJson($this->url, ['url' => 'https://fonts.google.com/specimen/Open+Sans'])->assertOk();
        $before = $this->certificate->refresh()->only(['font', 'google_font_family', 'google_font_path']);

        $this->postJson($this->url, ['url' => 'https://fonts.google.com/specimen/Nope'])->assertUnprocessable();

        $this->assertEquals($before, $this->certificate->refresh()->only(['font', 'google_font_family', 'google_font_path']));
    }

    public function test_google_cannot_be_selected_as_a_font_without_a_link_but_builtin_fonts_still_can(): void
    {
        $base = "/api/v1/events/{$this->certificate->event_id}/certificates/{$this->certificate->id}";

        $this->putJson($base, ['font' => 'google'])->assertUnprocessable()->assertJsonValidationErrors('font');

        $this->fakeGoogle();
        $this->postJson($this->url, ['url' => 'https://fonts.google.com/specimen/Open+Sans'])->assertOk();
        $this->putJson($base, ['font' => 'serif'])->assertOk()->assertJsonPath('data.font', 'serif');
        $this->putJson($base, ['font' => 'google'])->assertOk()->assertJsonPath('data.font', 'google');
    }

    public function test_checkers_and_other_organizations_are_denied(): void
    {
        $this->fakeGoogle();
        $body = ['url' => 'https://fonts.google.com/specimen/Open+Sans'];

        $this->actingAs(User::factory()->checker()->for($this->org)->create(), 'sanctum');
        $this->postJson($this->url, $body)->assertForbidden();

        $this->actingAs(User::factory()->orgAdmin()->for(Organization::factory()->create())->create(), 'sanctum');
        $this->postJson($this->url, $body)->assertNotFound();

        Http::assertNothingSent();
        $this->assertUnchanged();
    }

    public function test_archived_event_rejects_the_font_change(): void
    {
        $this->fakeGoogle();
        $this->certificate->event->forceFill(['archived_at' => now()])->save();

        $this->postJson($this->url, ['url' => 'https://fonts.google.com/specimen/Open+Sans'])
            ->assertForbidden()->assertExactJson(['message' => 'This event is archived.']);

        Http::assertNothingSent();
        $this->assertUnchanged();
    }

    private function assertUnchanged(): void
    {
        $fresh = $this->certificate->refresh();
        $this->assertSame('sans-serif', $fresh->font->value);
        $this->assertNull($fresh->google_font_family);
        $this->assertNull($fresh->google_font_path);
    }
}
