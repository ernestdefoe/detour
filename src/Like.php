<?php

namespace Ernestdefoe\Detour;

/**
 * A case-insensitive "contains" that behaves the same on every database:
 * PostgreSQL's LIKE is case-sensitive, and SQLite has no escape character
 * unless one is named, so `_` and `%` in a search would act as wildcards.
 */
final class Like
{
    /**
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     */
    public static function where(object $query, string $column, string $text, string $boolean = 'and'): void
    {
        $base = $query instanceof \Illuminate\Database\Eloquent\Builder ? $query->getQuery() : $query;
        $wrapped = $base->getGrammar()->wrap($column);
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($text, 'UTF-8')).'%';

        $query->whereRaw("LOWER($wrapped) LIKE ? ESCAPE '!'", [$pattern], $boolean);
    }
}
