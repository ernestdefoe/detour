<?php

namespace Ernestdefoe\Detour;

use Illuminate\Contracts\Database\Query\Builder;

/** A case-insensitive "contains" on every database (PostgreSQL's LIKE is case-sensitive). */
final class Like
{
    public static function operator(Builder $query): string
    {
        return $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    public static function pattern(string $text): string
    {
        return '%'.addcslashes($text, '%_\\').'%';
    }
}
