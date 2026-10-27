<?php

namespace Tests\Feature;

use App\Enums\CertificateOrientation;
use App\Enums\CertificatePageSize;
use App\Models\Certificate;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CertificateDraftTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $org;

    private Event $event;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->org = Organization::factory()->create();
        $this->event = Event::factory()->for($this->org)->create();
        $this->url = "/api/v1/events/{$this->event->id}/certificates";
    }

    private function asOrgAdmin(): void
    {
        $this->actingAs(User::factory()->orgAdmin()->for($this->org)->create(), 'sanctum');
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return ['title' => 'Certificate of Attendance', ...$overrides];
    }

    public function test_create_applies_a4_landscape_defaults_and_starts_as_draft(): void
    {
        $this->asOrgAdmin();

        $this->postJson($this->url, $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.title', 'Certificate of Attendance')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.page_size', 'a4')
            ->assertJsonPath('data.orientation', 'landscape')
            ->assertJsonPath('data.template_url', null)
            ->assertJsonPath('data.event_id', $this->event->id);

        $this->assertSame($this->org->id, Certificate::firstOrFail()->organization_id);
    }

    public function test_layout_is_stored_as_fractions_of_the_page_and_round_trips(): void
    {
        $this->asOrgAdmin();

        $this->postJson($this->url, $this->payload([
            'name_x' => 0.5, 'name_y' => 0.42, 'name_font_size' => 0.06,
            'font' => 'serif', 'color' => '#1a2b3c',
            'page_size' => 'short', 'orientation' => 'portrait',
        ]))->assertCreated()
            ->assertJsonPath('data.name_x', 0.5)
            ->assertJsonPath('data.name_y', 0.42)
            ->assertJsonPath('data.name_font_size', 0.06)
            ->assertJsonPath('data.font', 'serif')
            ->assertJsonPath('data.color', '#1a2b3c')
            ->assertJsonPath('data.page_size', 'short')
            ->assertJsonPath('data.orientation', 'portrait');

        // Changing only the page setup leaves the relative name layout untouched.
        $id = Certificate::firstOrFail()->id;
        $this->putJson("$this->url/$id", ['page_size' => 'long', 'orientation' => 'landscape'])
            ->assertOk()
            ->assertJsonPath('data.page_size', 'long')
            ->assertJsonPath('data.name_x', 0.5)
            ->assertJsonPath('data.name_y', 0.42)
            ->assertJsonPath('data.name_font_size', 0.06);
    }

    public function test_invalid_layout_values_are_rejected(): void
    {
        $this->asOrgAdmin();

        $this->postJson($this->url, $this->payload(['title' => '']))->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->postJson($this->url, $this->payload(['name_x' => 1.5]))->assertUnprocessable()->assertJsonValidationErrors('name_x');
        $this->postJson($this->url, $this->payload(['name_y' => -0.1]))->assertUnprocessable()->assertJsonValidationErrors('name_y');
        $this->postJson($this->url, $this->payload(['name_font_size' => 0]))->assertUnprocessable()->assertJsonValidationErrors('name_font_size');
        $this->postJson($this->url, $this->payload(['font' => 'comic-sans']))->assertUnprocessable()->assertJsonValidationErrors('font');
        $this->postJson($this->url, $this->payload(['color' => 'red']))->assertUnprocessable()->assertJsonValidationErrors('color');
        $this->postJson($this->url, $this->payload(['page_size' => 'a3']))->assertUnprocessable()->assertJsonValidationErrors('page_size');
        $this->postJson($this->url, $this->payload(['orientation' => 'diagonal']))->assertUnprocessable()->assertJsonValidationErrors('orientation');
        $this->assertDatabaseCount('certificates', 0);
    }

    public function test_an_event_can_have_several_certificates_and_they_are_listed_and_shown(): void
    {
        $this->asOrgAdmin();
        $this->postJson($this->url, $this->payload(['title' => 'Attendance']))->assertCreated();
        $id = $this->postJson($this->url, $this->payload(['title' => 'Participation']))->assertCreated()->json('data.id');
        Certificate::factory()->for(Event::factory()->for($this->org))->create(['title' => 'Other event']);

        $this->getJson($this->url)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("$this->url/$id")->assertOk()->assertJsonPath('data.title', 'Participation');
    }

    public function test_update_and_delete_a_draft_certificate_and_its_template(): void
    {
        $this->asOrgAdmin();
        $certificate = Certificate::factory()->for($this->event)->create();
        $this->postJson("$this->url/$certificate->id/template", ['template' => UploadedFile::fake()->image('t.png', 200, 100)])->assertOk();
        $path = $certificate->refresh()->template_path;
        Storage::disk('public')->assertExists($path);

        $this->putJson("$this->url/$certificate->id", ['title' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.title', 'Renamed');

        $this->deleteJson("$this->url/$certificate->id")->assertNoContent();
        $this->assertModelMissing($certificate);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_template_upload_accepts_png_and_jpg_and_replaces_the_previous_file(): void
    {
        $this->asOrgAdmin();
        $certificate = Certificate::factory()->for($this->event)->create();
        $endpoint = "$this->url/$certificate->id/template";

        $this->postJson($endpoint, ['template' => UploadedFile::fake()->image('a.png', 300, 200)])
            ->assertOk()->assertJsonStructure(['data' => ['template_url']]);
        $first = $certificate->refresh()->template_path;

        $this->postJson($endpoint, ['template' => UploadedFile::fake()->image('b.jpg', 300, 200)])->assertOk();
        $second = $certificate->refresh()->template_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_template_upload_rejects_other_types_and_oversized_files_with_clear_errors(): void
    {
        $this->asOrgAdmin();
        $certificate = Certificate::factory()->for($this->event)->create();
        $endpoint = "$this->url/$certificate->id/template";

        $this->postJson($endpoint, ['template' => UploadedFile::fake()->create('t.pdf', 10, 'application/pdf')])
            ->assertUnprocessable()->assertJsonValidationErrors('template');
        $this->postJson($endpoint, ['template' => UploadedFile::fake()->image('t.gif', 50, 50)])
            ->assertUnprocessable()->assertJsonValidationErrors('template');

        config(['certificates.template_max_bytes' => 1024]);
        $response = $this->postJson($endpoint, ['template' => UploadedFile::fake()->image('big.png', 400, 400)->size(2048)])
            ->assertUnprocessable()->assertJsonValidationErrors('template');
        $this->assertStringContainsString('larger than', $response->json('errors.template.0'));

        $this->assertNull($certificate->refresh()->template_path);
    }

    public function test_template_may_not_exceed_300_dpi_for_the_page(): void
    {
        $this->asOrgAdmin();
        $certificate = Certificate::factory()->for($this->event)->create(['page_size' => 'a4', 'orientation' => 'landscape']);
        $endpoint = "$this->url/$certificate->id/template";

        $this->postJson($endpoint, ['template' => UploadedFile::fake()->image('t.png', 3509, 2480)])
            ->assertUnprocessable()->assertJsonValidationErrors('template');
        $this->postJson($endpoint, ['template' => UploadedFile::fake()->image('t.png', 3508, 2481)])
            ->assertUnprocessable()->assertJsonValidationErrors('template');
        $this->assertNull($certificate->refresh()->template_path);

        $this->postJson($endpoint, ['template' => UploadedFile::fake()->image('t.png', 3508, 2480)])->assertOk();

        // Portrait swaps the limits, so the same image no longer fits.
        $this->putJson("$this->url/$certificate->id", ['orientation' => 'portrait'])
            ->assertUnprocessable()->assertJsonValidationErrors('orientation');
        $this->putJson("$this->url/$certificate->id", ['page_size' => 'long'])->assertOk();
    }

    public function test_page_limits_in_pixels_at_300_dpi(): void
    {
        $this->assertSame([3508, 2480], CertificatePageSize::A4->maxPixels(CertificateOrientation::Landscape));
        $this->assertSame([2480, 3508], CertificatePageSize::A4->maxPixels(CertificateOrientation::Portrait));
        $this->assertSame([2550, 3300], CertificatePageSize::Short->maxPixels(CertificateOrientation::Portrait));
        $this->assertSame([3900, 2550], CertificatePageSize::Long->maxPixels(CertificateOrientation::Landscape));
    }

    public function test_template_upload_limit_is_2_mb(): void
    {
        $this->assertSame(2 * 1024 * 1024, config('certificates.template_max_bytes'));
    }

    public function test_checkers_are_denied(): void
    {
        $certificate = Certificate::factory()->for($this->event)->create();
        $this->actingAs(User::factory()->checker()->for($this->org)->create(), 'sanctum');

        $this->getJson($this->url)->assertForbidden();
        $this->getJson("$this->url/$certificate->id")->assertForbidden();
        $this->postJson($this->url, $this->payload())->assertForbidden();
        $this->putJson("$this->url/$certificate->id", ['title' => 'X'])->assertForbidden();
        $this->deleteJson("$this->url/$certificate->id")->assertForbidden();
        $this->postJson("$this->url/$certificate->id/template", ['template' => UploadedFile::fake()->image('a.png')])->assertForbidden();
        $this->assertDatabaseCount('certificates', 1);
    }

    public function test_attendee_accounts_cannot_reach_the_certificates(): void
    {
        $certificate = Certificate::factory()->for($this->event)->create();
        // Attendee accounts belong to no organization, so the event is invisible to them.
        $this->actingAs(User::factory()->attendee()->create(), 'sanctum');

        $this->getJson($this->url)->assertNotFound();
        $this->postJson($this->url, $this->payload())->assertNotFound();
        $this->deleteJson("$this->url/$certificate->id")->assertNotFound();
        $this->assertDatabaseCount('certificates', 1);
    }

    public function test_another_organizations_admin_cannot_reach_the_certificates(): void
    {
        $certificate = Certificate::factory()->for($this->event)->create();
        $this->actingAs(User::factory()->orgAdmin()->for(Organization::factory()->create())->create(), 'sanctum');

        $this->getJson($this->url)->assertNotFound();
        $this->postJson($this->url, $this->payload())->assertNotFound();
        $this->putJson("$this->url/$certificate->id", ['title' => 'X'])->assertNotFound();
        $this->deleteJson("$this->url/$certificate->id")->assertNotFound();
        $this->assertDatabaseHas('certificates', ['id' => $certificate->id, 'title' => $certificate->title]);
    }

    public function test_super_admin_can_manage_any_organizations_certificates(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create(), 'sanctum');

        $id = $this->postJson($this->url, $this->payload())->assertCreated()->json('data.id');
        $this->assertSame($this->org->id, Certificate::withoutGlobalScopes()->findOrFail($id)->organization_id);
        $this->deleteJson("$this->url/$id")->assertNoContent();
    }

    public function test_a_certificate_cannot_be_reached_through_another_event(): void
    {
        $this->asOrgAdmin();
        $certificate = Certificate::factory()->for($this->event)->create();
        $otherEvent = Event::factory()->for($this->org)->create();

        $this->getJson("/api/v1/events/{$otherEvent->id}/certificates/{$certificate->id}")->assertNotFound();
    }

    public function test_archived_event_rejects_every_write_but_still_lists(): void
    {
        $this->asOrgAdmin();
        $certificate = Certificate::factory()->for($this->event)->create();
        $this->event->forceFill(['archived_at' => now()])->save();
        $archived = ['message' => 'This event is archived.'];

        $this->postJson($this->url, $this->payload())->assertForbidden()->assertExactJson($archived);
        $this->putJson("$this->url/$certificate->id", ['title' => 'X'])->assertForbidden()->assertExactJson($archived);
        $this->deleteJson("$this->url/$certificate->id")->assertForbidden()->assertExactJson($archived);
        $this->postJson("$this->url/$certificate->id/template", ['template' => UploadedFile::fake()->image('a.png')])
            ->assertForbidden()->assertExactJson($archived);

        $this->assertDatabaseHas('certificates', ['id' => $certificate->id, 'title' => $certificate->title]);
        $this->getJson($this->url)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("$this->url/$certificate->id")->assertOk();
    }

    public function test_actions_are_written_to_the_audit_log(): void
    {
        $this->asOrgAdmin();
        $id = $this->postJson($this->url, $this->payload())->json('data.id');
        $this->putJson("$this->url/$id", ['title' => 'New'])->assertOk();
        $this->deleteJson("$this->url/$id")->assertNoContent();

        foreach (['certificate.created', 'certificate.updated', 'certificate.deleted'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'auditable_id' => $id]);
        }
    }
}
