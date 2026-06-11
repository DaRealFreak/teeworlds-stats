<?php

namespace App\Service;

use Illuminate\Database\Eloquent\Builder;

class FuzzySearch
{
    /**
     * Add a weighted `relevance` score to the query based on how the
     * given column matches the term: exact (100), prefix (60), contains (40).
     * Mirrors the prior tom-lingham/searchy single-field behavior so callers
     * can chain ->having('relevance', '>', N)->limit(M)->get().
     */
    public static function on(Builder $query, string $column, string $term): Builder
    {
        $term = trim($term);
        $model = $query->getModel();
        $table = $model->getTable();

        return $query
            ->selectRaw("{$table}.*")
            ->selectRaw(
                "(CASE
                    WHEN {$column} = ? THEN 100
                    WHEN {$column} LIKE ? THEN 60
                    WHEN {$column} LIKE ? THEN 40
                    ELSE 0
                END) AS relevance",
                [$term, $term.'%', '%'.$term.'%']
            )
            // Group by the (unique) primary key so the query qualifies as an
            // aggregate query: SQLite only permits a HAVING clause on a grouped
            // query, and callers chain ->having('relevance', '>', N). Grouping on
            // a unique key is a no-op for the result set on MySQL/MariaDB too.
            ->groupBy("{$table}.{$model->getKeyName()}")
            ->orderByDesc('relevance');
    }
}
