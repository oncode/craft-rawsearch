<?php

namespace oncode\rawsearch\variables;

use craft\db\Query;
use craft\helpers\Template;
use oncode\rawsearch\models\Settings;
use oncode\rawsearch\RawSearch;
use Twig\Markup;

/**
 * Available in templates as `craft.rawSearch`.
 */
class RawSearchVariable
{
    public function settings(): Settings
    {
        return RawSearch::getInstance()->getSettings();
    }

    /**
     * Searches the index, see README for the params.
     *
     * @return array{results: array, pagination: \craft\web\twig\variables\Paginate, total: int}
     */
    public function search(array $params): array
    {
        return RawSearch::getInstance()->search->search($params);
    }

    /**
     * Renders search results with the same template the API uses for `html=1`.
     */
    public function renderResults(array $search, string $query): Markup
    {
        return Template::raw(RawSearch::getInstance()->search->renderResults($search, $query));
    }

    /**
     * Returns words starting with the given query.
     */
    public function autocomplete(array $params): array
    {
        return RawSearch::getInstance()->autocomplete->search($params);
    }

    /**
     * Returns the most searched queries.
     *
     * @param array $params `site` (handle, default all sites), `limit` (default 10)
     */
    public function mostSearched(array $params = []): array
    {
        $plugin = RawSearch::getInstance();
        $siteId = !empty($params['site']) ? $plugin->search->resolveSite($params['site'])->id : null;

        return $plugin->queries->getMostSearched($siteId, (int)($params['limit'] ?? 10));
    }

    /**
     * Returns a db query for the stored search queries.
     */
    public function queries(array $params = []): Query
    {
        $plugin = RawSearch::getInstance();
        $siteId = !empty($params['site']) ? $plugin->search->resolveSite($params['site'])->id : null;

        return $plugin->queries->find($siteId);
    }
}
