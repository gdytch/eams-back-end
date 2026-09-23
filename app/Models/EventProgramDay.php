<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['event_program_id', 'date', 'title', 'sort_order'])]
class EventProgramDay extends Model
{
    use HasFactory;

    protected $attributes = ['sort_order' => 0];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(EventProgram::class, 'event_program_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(EventProgramSection::class)->orderBy('sort_order')->orderBy('id');
    }
}
