<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['form_field_id', 'label', 'sort_order', 'archived_at'])]
class FormFieldOption extends Model
{
    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'archived_at' => 'datetime'];
    }

    /** @return BelongsTo<FormField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(FormField::class, 'form_field_id');
    }

    /** @return BelongsToMany<FormAnswer, $this> */
    public function answers(): BelongsToMany
    {
        return $this->belongsToMany(FormAnswer::class, 'form_answer_options');
    }

    /**
     * @param  Builder<FormFieldOption>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }
}
