<?php

namespace App\Models;

use App\Enums\FormFieldType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['form_id', 'type', 'label', 'help_text', 'is_required', 'sort_order', 'archived_at'])]
class FormField extends Model
{
    protected function casts(): array
    {
        return ['type' => FormFieldType::class, 'is_required' => 'boolean', 'sort_order' => 'integer', 'archived_at' => 'datetime'];
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** @return HasMany<FormFieldOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(FormFieldOption::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<FormAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(FormAnswer::class);
    }

    /**
     * @param  Builder<FormField>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }
}
