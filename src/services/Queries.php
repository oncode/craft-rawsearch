<?php

namespace oncode\rawsearch\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\StringHelper;
use oncode\rawsearch\db\Table;
use oncode\rawsearch\records\Query as QueryRecord;

/**
 * Stores search queries for the statistic.
 */
class Queries extends Component
{
    public function saveQuery(string $query, int $siteId, int $results, bool $or, int $mode): bool
    {
        // don't pollute the statistic while developing
        if (Craft::$app->getConfig()->getGeneral()->devMode) {
            return false;
        }

        $record = new QueryRecord([
            'siteId' => $siteId,
            'query' => StringHelper::safeTruncate(mb_strtolower(trim($query)), 255),
            'or' => $or,
            'mode' => $mode,
            'results' => $results,
        ]);

        try {
            return $record->save(false);
        } catch (\Throwable $e) {
            // a failing statistic must never break the search
            Craft::error('Could not save search query: ' . $e->getMessage(), 'rawsearch');
            return false;
        }
    }

    /**
     * Returns a query for the stored search queries.
     */
    public function find(?int $siteId = null): Query
    {
        return (new Query())
            ->select(['id', 'siteId', 'query', 'or', 'mode', 'results', 'dateCreated'])
            ->from(Table::QUERIES)
            ->filterWhere(['siteId' => $siteId])
            ->orderBy(['id' => SORT_DESC]);
    }

    /**
     * Returns the most searched queries with the number of searches and the lowest number of results.
     *
     * @return array List of `query`, `number` and `results`
     */
    public function getMostSearched(?int $siteId = null, int $limit = 10, ?\DateTime $since = null): array
    {
        $query = (new Query())
            ->select(['query', 'number' => 'COUNT(*)', 'results' => 'MIN([[results]])', 'lastSearched' => 'MAX([[dateCreated]])'])
            ->from(Table::QUERIES)
            ->filterWhere(['siteId' => $siteId])
            ->groupBy(['query'])
            ->orderBy(['number' => SORT_DESC, 'query' => SORT_ASC])
            ->limit($limit);

        if ($since) {
            $query->andWhere(['>=', 'dateCreated', \craft\helpers\Db::prepareDateForDb($since)]);
        }

        return array_map(fn($row) => [
            'query' => $row['query'],
            'number' => (int)$row['number'],
            'results' => (int)$row['results'],
            'lastSearched' => $row['lastSearched'],
        ], $query->all());
    }

    public function deleteAll(?int $siteId = null): int
    {
        return Craft::$app->getDb()->createCommand()
            ->delete(Table::QUERIES, $siteId ? ['siteId' => $siteId] : '')
            ->execute();
    }
}
