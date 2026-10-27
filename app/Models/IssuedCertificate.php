<?php

namespace App\Models;

use App\Enums\IssuedCertificateStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The generated file of one Certificate for one Attendee. */
#[Fillable([
    'certificate_id', 'attendee_id', 'status', 'failure_reason', 'name_too_long',
    'file_path', 'generated_at', 'emailed_at', 'file_deleted_at',
])]
class IssuedCertificate extends Model
{
    protected function casts(): array
    {
        return [
            'status' => IssuedCertificateStatus::class,
            'name_too_long' => 'boolean',
            'generated_at' => 'datetime',
            'emailed_at' => 'datetime',
            'file_deleted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Certificate, $this> */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    /** @return BelongsTo<Attendee, $this> */
    public function attendee(): BelongsTo
    {
        return $this->belongsTo(Attendee::class);
    }

    public function hasFile(): bool
    {
        return $this->status === IssuedCertificateStatus::Generated
            && $this->file_path !== null
            && $this->file_deleted_at === null;
    }
}
