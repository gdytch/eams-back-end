<?php

namespace App\Models;

use App\Enums\AttendanceMethod;
use Database\Factories\AttendanceRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['attendee_id', 'event_session_id', 'check_in_at', 'check_out_at', 'method', 'recorded_by', 'breakout_id', 'via_breakout_id', 'is_walk_in'])]
class AttendanceRecord extends Model
{
    /** @use HasFactory<AttendanceRecordFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'method' => AttendanceMethod::class,
            'is_walk_in' => 'boolean',
        ];
    }

    public static function slotColumn(EventSession|Breakout $slot): string
    {
        return $slot instanceof Breakout ? 'breakout_id' : 'event_session_id';
    }

    /** @return BelongsTo<Attendee, $this> */
    public function attendee(): BelongsTo
    {
        return $this->belongsTo(Attendee::class);
    }

    public function eventSession(): BelongsTo
    {
        return $this->belongsTo(EventSession::class);
    }

    public function breakout(): BelongsTo
    {
        return $this->belongsTo(Breakout::class);
    }

    public function viaBreakout(): BelongsTo
    {
        return $this->belongsTo(Breakout::class, 'via_breakout_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
