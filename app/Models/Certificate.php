<?php

namespace App\Models;

use App\Enums\CertificateFont;
use App\Enums\CertificateOrientation;
use App\Enums\CertificatePageSize;
use App\Enums\CertificateReleaseRule;
use App\Enums\CertificateStatus;
use App\Enums\IssuedCertificateStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\CertificateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

#[Fillable([
    'organization_id', 'event_id', 'title', 'template_path',
    'name_x', 'name_y', 'name_font_size', 'font', 'google_font_family', 'google_font_path', 'color',
    'page_size', 'orientation', 'status', 'published_at',
    'audience_union_ids', 'audience_mission_ids', 'audience_attendee_ids',
    'release_rule', 'include_breakouts', 'release_at', 'generation_run', 'email_enabled',
])]
class Certificate extends Model
{
    /** @use HasFactory<CertificateFactory> */
    use BelongsToOrganization, HasFactory;

    protected function casts(): array
    {
        return [
            'name_x' => 'float',
            'name_y' => 'float',
            'name_font_size' => 'float',
            'font' => CertificateFont::class,
            'page_size' => CertificatePageSize::class,
            'orientation' => CertificateOrientation::class,
            'status' => CertificateStatus::class,
            'published_at' => 'datetime',
            'audience_union_ids' => 'array',
            'audience_mission_ids' => 'array',
            'audience_attendee_ids' => 'array',
            'release_rule' => CertificateReleaseRule::class,
            'include_breakouts' => 'boolean',
            'email_enabled' => 'boolean',
            'release_at' => 'datetime',
        ];
    }

    /** Fields every stored Issued certificate depends on; locked while published. */
    public const DESIGN_FIELDS = ['name_x', 'name_y', 'name_font_size', 'font', 'color', 'page_size', 'orientation'];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<IssuedCertificate, $this> */
    public function issuedCertificates(): HasMany
    {
        return $this->hasMany(IssuedCertificate::class);
    }

    public function isPublished(): bool
    {
        return $this->status === CertificateStatus::Published;
    }

    /** Counts loaded for a whole list by generationCountsFor(), so a list needs one query, not one per row. */
    private ?array $loadedCounts = null;

    /**
     * Generation progress over the Issued certificates.
     *
     * @return array{total: int, generated: int, failed: int, name_too_long: int, emailed: int, files_deleted_at: ?string}
     */
    public function generationCounts(): array
    {
        return $this->loadedCounts ?? self::generationCountsFor(collect([$this]))[$this->id];
    }

    /**
     * Loads the counts of all given Certificates with one grouped query and remembers them on each model.
     *
     * @param  Collection<int, Certificate>  $certificates
     * @return array<int, array{total: int, generated: int, failed: int, name_too_long: int, emailed: int, files_deleted_at: ?string}>
     */
    public static function generationCountsFor(Collection $certificates): array
    {
        $rows = IssuedCertificate::query()->visible()->toBase()
            ->whereIn('certificate_id', $certificates->pluck('id'))
            ->groupBy('certificate_id')
            ->selectRaw(
                'certificate_id, count(*) as total, '
                .'coalesce(sum(case when status = ? then 1 else 0 end), 0) as generated, '
                .'coalesce(sum(case when status = ? then 1 else 0 end), 0) as failed, '
                .'coalesce(sum(case when name_too_long then 1 else 0 end), 0) as name_too_long, '
                .'coalesce(sum(case when emailed_at is not null then 1 else 0 end), 0) as emailed, '
                .'max(file_deleted_at) as files_deleted_at',
                [IssuedCertificateStatus::Generated->value, IssuedCertificateStatus::Failed->value],
            )->get()->keyBy('certificate_id');

        $counts = [];
        foreach ($certificates as $certificate) {
            $row = $rows->get($certificate->id);
            $counts[$certificate->id] = $certificate->loadedCounts = [
                'total' => (int) ($row->total ?? 0),
                'generated' => (int) ($row->generated ?? 0),
                'failed' => (int) ($row->failed ?? 0),
                'name_too_long' => (int) ($row->name_too_long ?? 0),
                'emailed' => (int) ($row->emailed ?? 0),
                // Set once the retention purge removed the files; Regenerate clears it again.
                'files_deleted_at' => ($row->files_deleted_at ?? null) ? Carbon::parse($row->files_deleted_at)->toJSON() : null,
            ];
        }

        return $counts;
    }
}
