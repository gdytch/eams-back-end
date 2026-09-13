<?php

namespace App\Models;

use App\Enums\IdCardGridDownloadStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['event_id', 'requested_by', 'registration_ids', 'status', 'progress_percentage', 'file_path', 'failure_reason', 'completed_at'])]
class IdCardGridDownload extends Model
{
    /** @use HasFactory */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'registration_ids' => 'array',
            'status' => IdCardGridDownloadStatus::class,
            'progress_percentage' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
