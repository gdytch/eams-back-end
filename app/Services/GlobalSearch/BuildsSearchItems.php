<?php

namespace App\Services\GlobalSearch;

use Illuminate\Database\Eloquent\Builder;

/** LIKE matching and result-item building shared by the Global search category classes. */
trait BuildsSearchItems
{
    protected function matches(Builder $query, array $columns, string $like): Builder
    {
        return $query->where(function (Builder $match) use ($columns, $like) {
            foreach ($columns as $column) {
                $match->orWhereRaw($this->likeSql($match, $column), [$like, '\\']);
            }
        });
    }

    /** A LIKE predicate with an explicit backslash ESCAPE, so escaped wildcards behave the same on every driver. */
    protected function likeSql(Builder $query, string $column): string
    {
        return $query->getQuery()->getGrammar()->wrap($column).' like ? escape ?';
    }

    protected function item(string $type, int $id, ?string $title, ?string $subtitle = null, ?string $context = null, ?int $eventId = null, array $extra = []): array
    {
        return array_merge([
            'type' => $type,
            'id' => $id,
            'title' => $title ?: 'Untitled',
            'subtitle' => $subtitle,
            'context' => $context,
            'event_id' => $eventId,
        ], $extra);
    }
}
