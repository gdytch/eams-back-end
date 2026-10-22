<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['form_submission_id', 'form_field_id', 'value', 'file_path', 'file_name', 'file_deleted_at'])]
class FormAnswer extends Model
{
    protected function casts(): array
    {
        return ['file_deleted_at' => 'datetime'];
    }

    /** @return BelongsTo<FormSubmission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class, 'form_submission_id');
    }

    /** @return BelongsTo<FormField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(FormField::class, 'form_field_id');
    }

    /** @return BelongsToMany<FormFieldOption, $this> */
    public function options(): BelongsToMany
    {
        return $this->belongsToMany(FormFieldOption::class, 'form_answer_options');
    }

    /** A deleted file still counts as answered. Choice answers hold their picks in `options`. */
    public function isAnswered(): bool
    {
        return filled($this->value) || $this->file_path !== null || $this->file_deleted_at !== null || $this->options->isNotEmpty();
    }
}
