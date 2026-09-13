<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SystemLogTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $logsDir;

    private string $fixtureDate = '2026-09-13';

    protected function setUp(): void
    {
        parent::setUp();

        $this->logsDir = storage_path('logs');
        if (! is_dir($this->logsDir)) {
            mkdir($this->logsDir, 0755, true);
        }

        // Create fixture log file with test entries
        $this->createFixtureLog();
    }

    protected function tearDown(): void
    {
        // Clean up fixture log file
        $fixturePath = $this->logsDir."/laravel-{$this->fixtureDate}.log";
        if (file_exists($fixturePath)) {
            unlink($fixturePath);
        }

        parent::tearDown();
    }

    private function createFixtureLog(): void
    {
        $fixturePath = $this->logsDir."/laravel-{$this->fixtureDate}.log";

        $content = <<<'LOG'
[2026-09-13 08:00:00] laravel.info: Application started
[2026-09-13 08:15:30] laravel.warning: Cache miss on key: user:123
[2026-09-13 08:30:45] laravel.error: Database connection error
Stack trace:
  #0 app/Services/User.php(42): PDO->connect()
  #1 app/Http/Controllers/UserController.php(10): User->find(123)
[2026-09-13 09:00:00] laravel.debug: Processing background job: send-email
[2026-09-13 09:15:15] laravel.error: Failed to send email to user@example.com
Error message: SMTP connection timeout
Stack trace:
  #0 app/Mail/UserMail.php(30): Swift_Transport->send()
  #1 app/Jobs/SendEmailJob.php(15): UserMail->send()
[2026-09-13 10:00:00] laravel.info: Scheduled task completed: cleanup-old-files
[2026-09-13 10:30:22] laravel.critical: Out of memory error
Stack trace:
  #0 memory error in application
[2026-09-13 11:00:00] laravel.notice: User registration: user123@test.com
LOG;

        file_put_contents($fixturePath, $content);
    }

    public function test_super_admin_can_list_available_log_dates(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/system-logs/dates');

        $response->assertOk();
        $this->assertIsArray($response->json('dates'));

        $dates = collect($response->json('dates'))->pluck('date')->toArray();
        $this->assertContains($this->fixtureDate, $dates);
    }

    public function test_super_admin_can_view_log_entries_for_a_date(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin, 'sanctum')->getJson(
            '/api/v1/system-logs?date='.$this->fixtureDate
        );

        $response->assertOk();
        $this->assertGreaterThan(0, $response->json('meta.total'));

        $entries = $response->json('data');
        $this->assertIsArray($entries);
        $this->assertNotEmpty($entries);

        // Verify entry structure
        $entry = $entries[0];
        $this->assertArrayHasKey('timestamp', $entry);
        $this->assertArrayHasKey('channel', $entry);
        $this->assertArrayHasKey('level', $entry);
        $this->assertArrayHasKey('message', $entry);
        $this->assertArrayHasKey('raw', $entry);
    }

    public function test_entries_are_returned_newest_first(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin, 'sanctum')->getJson(
            '/api/v1/system-logs?date='.$this->fixtureDate
        );

        $response->assertOk();
        $entries = $response->json('data');

        // The newest entry should be first (last in the file)
        $firstEntry = $entries[0];
        $this->assertStringContainsString('11:00:00', $firstEntry['timestamp']);
    }

    public function test_pagination_works(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response1 = $this->actingAs($superAdmin, 'sanctum')->getJson(
            '/api/v1/system-logs?date='.$this->fixtureDate.'&per_page=2&page=1'
        );

        $response1->assertOk();
        $this->assertSame(2, count($response1->json('data')));
        $this->assertSame(8, $response1->json('meta.total'));
        $this->assertSame(1, $response1->json('meta.current_page'));

        $response2 = $this->actingAs($superAdmin, 'sanctum')->getJson(
            '/api/v1/system-logs?date='.$this->fixtureDate.'&per_page=2&page=2'
        );

        $response2->assertOk();
        $this->assertSame(2, count($response2->json('data')));
        $this->assertSame(2, $response2->json('meta.current_page'));
    }

    public function test_level_filter_works(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin, 'sanctum')->getJson(
            '/api/v1/system-logs?date='.$this->fixtureDate.'&level=error'
        );

        $response->assertOk();
        $this->assertSame(2, $response->json('meta.total')); // 2 error entries

        $entries = $response->json('data');
        foreach ($entries as $entry) {
            $this->assertSame('error', $entry['level']);
        }
    }

    public function test_search_filter_works(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin, 'sanctum')->getJson(
            '/api/v1/system-logs?date='.$this->fixtureDate.'&search=stack%20trace'
        );

        $response->assertOk();
        $this->assertGreaterThan(0, $response->json('meta.total')); // Entries with stack traces

        $entries = $response->json('data');
        foreach ($entries as $entry) {
            $this->assertStringContainsString('Stack trace', $entry['raw']);
        }
    }

    public function test_multi_line_entries_with_stack_trace_parse_correctly(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin, 'sanctum')->getJson(
            '/api/v1/system-logs?date='.$this->fixtureDate.'&level=error'
        );

        $response->assertOk();
        $entries = $response->json('data');

        // Find the database error entry (should have multi-line stack trace)
        $dbErrorEntry = collect($entries)
            ->first(fn ($e) => str_contains($e['message'], 'Database connection error'));

        $this->assertNotNull($dbErrorEntry);
        $this->assertStringContainsString('Stack trace', $dbErrorEntry['raw']);
        $this->assertStringContainsString('PDO->connect', $dbErrorEntry['raw']);
    }

    public function test_invalid_level_filter_returns_validation_error(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin, 'sanctum')->getJson(
            '/api/v1/system-logs?date='.$this->fixtureDate.'&level=invalid_level'
        );

        $response->assertUnprocessable();
    }

    public function test_invalid_date_format_returns_validation_error(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin, 'sanctum')->getJson(
            '/api/v1/system-logs?date=13-09-2026'
        );

        $response->assertUnprocessable();
    }

    public function test_nonexistent_date_returns_empty_list(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin, 'sanctum')->getJson(
            '/api/v1/system-logs?date=2020-01-01'
        );

        $response->assertOk();
        $this->assertSame(0, $response->json('meta.total'));
        $this->assertEmpty($response->json('data'));
    }

    public function test_org_admin_cannot_view_system_logs(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')->getJson(
            '/api/v1/system-logs?date='.$this->fixtureDate
        );

        $response->assertForbidden();
    }

    public function test_checker_cannot_view_system_logs(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')->getJson(
            '/api/v1/system-logs?date='.$this->fixtureDate
        );

        $response->assertForbidden();
    }

    public function test_attendee_cannot_view_system_logs(): void
    {
        $org = Organization::factory()->create();
        $attendee = User::factory()->attendee()->for($org)->create();

        $response = $this->actingAs($attendee, 'sanctum')->getJson(
            '/api/v1/system-logs?date='.$this->fixtureDate
        );

        $response->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_view_system_logs(): void
    {
        $response = $this->getJson('/api/v1/system-logs?date='.$this->fixtureDate);

        $response->assertUnauthorized();
    }

    public function test_unauthenticated_user_cannot_view_log_dates(): void
    {
        $response = $this->getJson('/api/v1/system-logs/dates');

        $response->assertUnauthorized();
    }

    public function test_search_is_case_insensitive(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $responseUpper = $this->actingAs($superAdmin, 'sanctum')->getJson(
            '/api/v1/system-logs?date='.$this->fixtureDate.'&search=STACK%20TRACE'
        );

        $responseLower = $this->actingAs($superAdmin, 'sanctum')->getJson(
            '/api/v1/system-logs?date='.$this->fixtureDate.'&search=stack%20trace'
        );

        $responseUpper->assertOk();
        $responseLower->assertOk();
        $this->assertSame($responseUpper->json('meta.total'), $responseLower->json('meta.total'));
    }

    public function test_level_filter_is_case_insensitive(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $responseUpper = $this->actingAs($superAdmin, 'sanctum')->getJson(
            '/api/v1/system-logs?date='.$this->fixtureDate.'&level=ERROR'
        );

        $responseLower = $this->actingAs($superAdmin, 'sanctum')->getJson(
            '/api/v1/system-logs?date='.$this->fixtureDate.'&level=error'
        );

        $responseUpper->assertOk();
        $responseLower->assertOk();
        $this->assertSame($responseUpper->json('meta.total'), $responseLower->json('meta.total'));
    }
}
