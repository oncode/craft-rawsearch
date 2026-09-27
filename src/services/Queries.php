<?php

namespace oncode\rawsearch\services;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Support\Query;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use oncode\rawsearch\db\Table;
use oncode\rawsearch\records\Query as QueryRecord;

/**
 * Stores search queries for the statistic.
 */
class Queries
{
    public function saveQuery(string $query, int $siteId, int $results, bool $or, int $mode): bool
    {
        // don't pollute the statistic while developing
        if (Cms::config()->devMode) {
            return false;
        }

        $record = new QueryRecord([
            'siteId' => $siteId,
            'query' => mb_substr(mb_strtolower(trim($query)), 0, 255),
            'or' => $or,
            'mode' => $mode,
            'results' => $results,
        ]);

        try {
            return $record->save();
        } catch (\Throwable $e) {
            // a failing statistic must never break the search
            Log::error('RawSearch: could not save search query: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Returns a query for the stored search queries.
     */
    public function find(?int $siteId = null): Builder
    {
        return DB::table(Table::QUERIES)
            ->select(['id', 'siteId', 'query', 'or', 'mode', 'results', 'dateCreated'])
            ->when($siteId, fn($query) => $query->where('siteId', $siteId))
            ->orderByDesc('id');
    }

    /**
     * Returns the most searched queries with the number of searches and the lowest number of results.
     *
     * @return array List of `query`, `number` and `results`
     */
    public function getMostSearched(?int $siteId = null, int $limit = 10, ?\DateTime $since = null): array
    {
        $grammar = DB::getQueryGrammar();

        $query = DB::table(Table::QUERIES)
            ->select('query')
            ->selectRaw('COUNT(*) AS ' . $grammar->wrap('number'))
            ->selectRaw('MIN(' . $grammar->wrap('results') . ') AS ' . $grammar->wrap('results'))
            ->selectRaw('MAX(' . $grammar->wrap('dateCreated') . ') AS ' . $grammar->wrap('lastSearched'))
            ->when($siteId, fn($query) => $query->where('siteId', $siteId))
            ->when($since, fn($query) => $query->where('dateCreated', '>=', Query::prepareDateForDb($since)))
            ->groupBy('query')
            ->orderByDesc('number')
            ->orderBy('query')
            ->limit($limit);

        return $query->get()->map(fn($row) => [
            'query' => $row->query,
            'number' => (int)$row->number,
            'results' => (int)$row->results,
            'lastSearched' => $row->lastSearched,
        ])->all();
    }

    public function deleteAll(?int $siteId = null): int
    {
        return DB::table(Table::QUERIES)
            ->when($siteId, fn($query) => $query->where('siteId', $siteId))
            ->delete();
    }
}
