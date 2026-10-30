<?php

namespace App\Services\GlobalSearch;

use App\Models\Form;
use Illuminate\Database\Eloquent\Builder;

/** Global search over Forms: title, description and active field labels. */
class FormSearch
{
    use BuildsSearchItems;

    /**
     * Flags (`matched_itself`) in SQL whether the Form's own title or description matched, and eager-loads only the
     * active fields whose label matched, so the subtitle needs no further query.
     */
    public function query(Builder $events, string $like): Builder
    {
        $query = Form::withoutGlobalScopes()
            ->whereIn('event_id', (clone $events)->select('events.id'))
            ->with([
                'event' => fn ($event) => $event->withoutGlobalScopes(),
                'fields' => fn ($field) => $this->matches($field->getQuery()->active(), ['label'], $like)->orderBy('id'),
            ]);
        $itself = $this->likeSql($query, 'forms.title').' or '.$this->likeSql($query, 'forms.description');
        $query->selectRaw("forms.*, case when {$itself} then 1 else 0 end as matched_itself", [$like, '\\', $like, '\\']);

        return $query->where(function (Builder $match) use ($like) {
            $this->matches($match, ['title', 'description'], $like)
                ->orWhereHas('fields', fn (Builder $field) => $this->matches($field->active(), ['label'], $like));
        });
    }

    public function subtitle(Form $form): string
    {
        $label = $form->fields->first()?->label;
        if (! $form->matched_itself && $label !== null) {
            return 'Field: '.$label;
        }

        return ($form->is_required ? 'Required' : 'Optional').' · '.ucfirst($form->status->value);
    }
}
