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

#[Fillable([
    'organization_id', 'event_id', 'title', 'template_path',
    'name_x', 'name_y', 'name_font_size', 'font', 'google_font_family', 'google_font_path', 'color',
    'page_size', 'orientation', 'status', 'published_at',
    'audience_union_ids', 'audience_mission_ids', 'audience_attendee_ids',
    'release_rule', 'include_breakouts', 'release_at', 'generation_run',
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

    /**
     * Generation progress over the Issued certificates.
     *
     * @return array{total: int, generated: int, failed: int, name_too_long: int}
     */
    public function generationCounts(): array
    {
        $row = $this->issuedCertificates()->visible()->toBase()->selectRaw(
            'count(*) as total, '
            .'coalesce(sum(case when status = ? then 1 else 0 end), 0) as generated, '
            .'coalesce(sum(case when status = ? then 1 else 0 end), 0) as failed, '
            .'coalesce(sum(case when name_too_long then 1 else 0 end), 0) as name_too_long',
            [IssuedCertificateStatus::Generated->value, IssuedCertificateStatus::Failed->value],
        )->first();

        return [
            'total' => (int) $row->total,
            'generated' => (int) $row->generated,
            'failed' => (int) $row->failed,
            'name_too_long' => (int) $row->name_too_long,
        ];
    }
}
