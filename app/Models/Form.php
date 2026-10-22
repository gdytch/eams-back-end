<?php

namespace App\Models;

use App\Enums\FormStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\FormFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable(['organization_id', 'event_id', 'title', 'description', 'is_required', 'status', 'sort_order'])]
class Form extends Model
{
    /** @use HasFactory<FormFactory> */
    use BelongsToOrganization, HasFactory;

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'status' => FormStatus::class, 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<FormField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<FormSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    /** @return HasManyThrough<FormAnswer, FormField, $this> */
    public function answers(): HasManyThrough
    {
        return $this->hasManyThrough(FormAnswer::class, FormField::class);
    }

    public function isPublished(): bool
    {
        return $this->status === FormStatus::Published;
    }

    /**
     * @param  Builder<Form>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', FormStatus::Published->value);
    }
}
