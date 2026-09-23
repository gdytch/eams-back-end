<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['event_program_day_id', 'title', 'start_time', 'end_time', 'description', 'sort_order'])]
class EventProgramSection extends Model
{
    use HasFactory;

    protected $attributes = ['sort_order' => 0];

    public function day(): BelongsTo
    {
        return $this->belongsTo(EventProgramDay::class, 'event_program_day_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EventProgramItem::class)->orderBy('order')->orderBy('id');
    }
}
