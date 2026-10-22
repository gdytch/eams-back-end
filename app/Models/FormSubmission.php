<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['form_id', 'attendee_id'])]
class FormSubmission extends Model
{
    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** @return BelongsTo<Attendee, $this> */
    public function attendee(): BelongsTo
    {
        return $this->belongsTo(Attendee::class);
    }

    /** @return HasMany<FormAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(FormAnswer::class);
    }
}
