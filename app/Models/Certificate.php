<?php

namespace App\Models;

use App\Enums\CertificateFont;
use App\Enums\CertificateOrientation;
use App\Enums\CertificatePageSize;
use App\Enums\CertificateStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\CertificateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'event_id', 'title', 'template_path',
    'name_x', 'name_y', 'name_font_size', 'font', 'color',
    'page_size', 'orientation', 'status', 'published_at',
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
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
