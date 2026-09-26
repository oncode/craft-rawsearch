<?php

namespace oncode\rawsearch\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\db\ElementQueryInterface;
use craft\models\Site;
use craft\web\twig\variables\Paginate;
use craft\web\View;
use oncode\rawsearch\db\Table;
use oncode\rawsearch\events\DbQueryEvent;
use oncode\rawsearch\events\ElementQueryEvent;
use oncode\rawsearch\events\RowsEvent;
use oncode\rawsearch\events\SearchEvent;
use oncode\rawsearch\helpers\IndexHelper;
use oncode\rawsearch\helpers\StringHelper;
use oncode\rawsearch\helpers\WordRadiusExtractor;
use oncode\rawsearch\RawSearch;
use yii\base\InvalidArgumentException;

/**
 * Searches the index and returns weighted results with snippets.
 */
class Search extends Component
{
    /** Fired before the index gets searched. The `dbQuery` can still be modified. */
    public const EVENT_BEFORE_SEARCH = 'beforeSearch';

    /** Fired after the search, with the results. */
    public const EVENT_AFTER_SEARCH = 'afterSearch';

    /** Allows modifying the db query that searches the index table. */
    public const EVENT_MODIFY_SEARCH_QUERY = 'modifySearchQuery';

    /** Allows modifying the element query used to filter and fetch found elements (per element type). */
    public const EVENT_MODIFY_ELEMENT_QUERY = 'modifyElementQuery';

    /** Allows modifying the grouped result rows after they got sorted (before pagination). */
    public const EVENT_MODIFY_RESULT_ROWS = 'modifyResultRows';

    /** Allows modifying the results of the current page. */
    public const EVENT_MODIFY_RESULTS = 'modifyResults';

    public const MODE_EXACT = WordRadiusExtractor::MODE_EXACT;
    public const MODE_WORD_START = WordRadiusExtractor::MODE_WORD_START;
    public const MODE_WORD_CONTENT = WordRadiusExtractor::MODE_WORD_CONTENT;

    /**
     * Default search config.
     */
    public array $defaultConfig = [
        // site handle, id or model (default: current site)
        'site' => null,
        // element types to search in (class names or ref handles like `entry`, default: all)
        'elementTypes' => null,
        // whether the words are searched with OR instead of AND
        'or' => false,
        // 1 = exact word, 2 = word start, 3 = word content
        'mode' => self::MODE_WORD_START,
        'resultsPerPage' => 10,
        // page to show (default: page of the current request)
        'page' => null,
        // sort the results according to the weight configuration
        'weightedSort' => true,
        // status passed to the element queries, `default` uses the element type default (e.g. live entries only),
        // `null` returns elements of any status
        'status' => 'default',
        // whether the query gets stored for the statistic
        'statistic' => true,
        'extract' => [
            'enabled' => true,
            'radius' => 4,
            'limit' => 10,
            'wrap' => '<mark>{phrase}</mark>',
        ],
    ];

    /**
     * Searches the index.
     *
     * @return array{results: array, pagination: Paginate, total: int}
     */
    public function search(array $params): array
    {
        $query = trim((string)($params['query'] ?? ''));

        if ($query === '') {
            throw new InvalidArgumentException('No query given');
        }

        $startTime = microtime(true);
        $config = $this->resolveConfig($params);
        $siteId = $config['siteId'];
        $normalizedQuery = $this->normalizeQuery($query);

        if ($normalizedQuery === '') {
            return [
                'results' => [],
                'pagination' => $this->createPagination(0, 1, $config['resultsPerPage']),
                'total' => 0,
            ];
        }

        $dbQuery = $this->buildSearchQuery($normalizedQuery, $config);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_SEARCH)) {
            $this->trigger(self::EVENT_BEFORE_SEARCH, new SearchEvent([
                'query' => $query,
                'normalizedQuery' => $normalizedQuery,
                'config' => $config,
                'dbQuery' => $dbQuery,
            ]));
        }

        [$elementRows, $elementQueries] = $this->fetchElementRows($dbQuery, $normalizedQuery, $config);

        if ($config['weightedSort']) {
            RawSearch::getInstance()->sort->weightedSort($elementRows, $normalizedQuery);
        }

        if ($this->hasEventHandlers(self::EVENT_MODIFY_RESULT_ROWS)) {
            $event = new RowsEvent(['rows' => $elementRows, 'normalizedQuery' => $normalizedQuery, 'config' => $config]);
            $this->trigger(self::EVENT_MODIFY_RESULT_ROWS, $event);
            $elementRows = array_values($event->rows);
        }

        $total = count($elementRows);
        $pagination = $this->createPagination($total, $config['page'], $config['resultsPerPage']);
        $pageRows = array_slice($elementRows, ($pagination->currentPage - 1) * $config['resultsPerPage'], $config['resultsPerPage']);
        $results = $this->determineResults($pageRows, $elementQueries);

        if ($config['extract']['enabled']) {
            $this->addSnippets($results, $normalizedQuery, $config);
        }

        // remove texts from result rows to save some bytes
        foreach ($results as $key => $result) {
            foreach ($result['rows'] ?? [] as $rowKey => $row) {
                unset($results[$key]['rows'][$rowKey]['text'], $results[$key]['rows'][$rowKey]['normalizedWords']);
            }
        }

        if ($this->hasEventHandlers(self::EVENT_MODIFY_RESULTS)) {
            $event = new RowsEvent(['rows' => $results, 'normalizedQuery' => $normalizedQuery, 'config' => $config]);
            $this->trigger(self::EVENT_MODIFY_RESULTS, $event);
            $results = $event->rows;
        }

        Craft::info(sprintf('Search for "%s" took %.4fs', $query, microtime(true) - $startTime), 'rawsearch');

        if ($config['statistic'] && $pagination->currentPage === 1) {
            RawSearch::getInstance()->queries->saveQuery($query, $siteId, $total, (bool)$config['or'], (int)$config['mode']);
        }

        if ($this->hasEventHandlers(self::EVENT_AFTER_SEARCH)) {
            $this->trigger(self::EVENT_AFTER_SEARCH, new SearchEvent([
                'query' => $query,
                'normalizedQuery' => $normalizedQuery,
                'config' => $config,
                'results' => $results,
                'pagination' => $pagination,
                'total' => $total,
            ]));
        }

        return [
            'results' => $results,
            'pagination' => $pagination,
            'total' => $total,
        ];
    }

    /**
     * Renders search results as HTML, used by the API (`html=1`) and `craft.rawSearch.renderResults()`.
     * The default template can be overridden with `templates/rawsearch/_search.twig`.
     *
     * @param array $search The return value of search()
     */
    public function renderResults(array $search, string $query): string
    {
        $view = Craft::$app->getView();
        $variables = ['search' => $search, 'query' => $query];

        if ($view->doesTemplateExist('rawsearch/_search', View::TEMPLATE_MODE_SITE)) {
            return $view->renderTemplate('rawsearch/_search', $variables, View::TEMPLATE_MODE_SITE);
        }

        return $view->renderTemplate('rawsearch/_frontend/search', $variables, View::TEMPLATE_MODE_CP);
    }

    /**
     * Returns the number of results a search would find, without sorting, snippets and loading the elements.
     * Accepts the same params as search().
     */
    public function countResults(array $params): int
    {
        $config = $this->resolveConfig($params);
        $normalizedQuery = $this->normalizeQuery(trim((string)($params['query'] ?? '')));

        if ($normalizedQuery === '') {
            return 0;
        }

        [$elementRows] = $this->fetchElementRows($this->buildSearchQuery($normalizedQuery, $config), $normalizedQuery, $config);

        return count($elementRows);
    }

    /**
     * Fetches the index rows grouped by element and removes the elements that must not be found.
     *
     * @return array{0: array, 1: array<string,ElementQueryInterface>} Element rows and the element queries by type
     */
    protected function fetchElementRows(Query $dbQuery, string $normalizedQuery, array $config): array
    {
        $elementRows = $this->getRowsGroupedByElements($dbQuery);

        if (!$config['or']) {
            $elementRows = $this->filterElementRowsContainingAllWords($elementRows, $normalizedQuery, $config['mode']);
        }

        $elementQueries = $this->filterElementRows($elementRows, $config);

        return [$elementRows, $elementQueries];
    }

    /**
     * Merges the given params into the default config and resolves site and element types.
     */
    public function resolveConfig(array $params): array
    {
        // `extract: false` is a shortcut to disable the snippets
        if (array_key_exists('extract', $params) && !is_array($params['extract'])) {
            $params['extract'] = ['enabled' => (bool)$params['extract']];
        }

        $config = array_replace_recursive($this->defaultConfig, $params);

        $site = $this->resolveSite($config['site']);
        $config['site'] = $site->handle;
        $config['siteId'] = $site->id;
        $config['elementTypes'] = $this->resolveElementTypes($config['elementTypes']);
        $config['or'] = (bool)$config['or'];
        $config['mode'] = in_array((int)$config['mode'], [self::MODE_EXACT, self::MODE_WORD_START, self::MODE_WORD_CONTENT], true)
            ? (int)$config['mode']
            : self::MODE_WORD_START;
        $config['resultsPerPage'] = max(1, (int)$config['resultsPerPage']);
        $config['page'] = is_numeric($config['page']) ? (int)$config['page'] : null;
        $config['weightedSort'] = (bool)$config['weightedSort'];
        $config['statistic'] = $config['statistic'] && RawSearch::getInstance()->getSettings()->statistic;
        $config['extract']['enabled'] = (bool)$config['extract']['enabled'];
        $config['extract']['radius'] = max(0, (int)$config['extract']['radius']);
        $config['extract']['limit'] = max(0, (int)$config['extract']['limit']);

        return $config;
    }

    /**
     * Normalizes a query like the index, blacklisted words are removed since they are not indexed.
     */
    public function normalizeQuery(string $query): string
    {
        return StringHelper::normalize($query, RawSearch::getInstance()->index->getBlacklistedWords());
    }

    public function resolveSite(mixed $site): Site
    {
        $sites = Craft::$app->getSites();

        if ($site instanceof Site) {
            return $site;
        }

        if ($site !== null && $site !== '') {
            $resolved = is_numeric($site) ? $sites->getSiteById((int)$site) : $sites->getSiteByHandle((string)$site);

            if (!$resolved) {
                throw new InvalidArgumentException("Invalid site: $site");
            }

            return $resolved;
        }

        return $sites->getCurrentSite();
    }

    /**
     * @return class-string<ElementInterface>[]|null
     */
    public function resolveElementTypes(mixed $elementTypes): ?array
    {
        if ($elementTypes === null || $elementTypes === '' || $elementTypes === []) {
            return null;
        }

        if (is_string($elementTypes)) {
            $elementTypes = explode(',', $elementTypes);
        }

        $resolved = [];

        foreach ((array)$elementTypes as $elementType) {
            $class = RawSearch::getInstance()->elementTypeConfigs->resolveElementType(trim((string)$elementType));

            if ($class === null) {
                throw new InvalidArgumentException("Invalid element type: $elementType");
            }

            $resolved[] = $class;
        }

        return $resolved;
    }

    /**
     * Builds the db query that searches the index.
     */
    public function buildSearchQuery(string $normalizedQuery, array $config): Query
    {
        $dbQuery = (new Query())
            ->select(['elementId', 'siteId', 'type', 'attribute', 'fieldId', 'normalizedWords', 'text'])
            ->from(Table::INDEX)
            ->where(['siteId' => $config['siteId']]);

        $limit = RawSearch::getInstance()->getSettings()->rowLimitSearch;

        if ($limit) {
            $dbQuery->limit($limit);
        }

        if ($config['elementTypes']) {
            $dbQuery->andWhere(['type' => $config['elementTypes']]);
        }

        // AND searches fetch rows matching any word, all words have to be found per element (not per row)
        $dbQuery->andWhere($this->buildWordsCondition(explode(' ', $normalizedQuery), true, $config['mode']));

        if ($this->hasEventHandlers(self::EVENT_MODIFY_SEARCH_QUERY)) {
            $this->trigger(self::EVENT_MODIFY_SEARCH_QUERY, new DbQueryEvent([
                'dbQuery' => $dbQuery,
                'normalizedQuery' => $normalizedQuery,
                'config' => $config,
            ]));
        }

        return $dbQuery;
    }

    /**
     * Builds the condition to find the given normalized words.
     * Words in the fulltext index are searched with MATCH, all others with LIKE.
     */
    public function buildWordsCondition(array $words, bool $or, int $mode): array
    {
        $conditions = [];

        foreach (array_values(array_unique($words)) as $i => $word) {
            if ($mode !== self::MODE_WORD_CONTENT && IndexHelper::isFulltextWord($word)) {
                $param = ':rawsearchWord' . $i;
                $conditions[] = new \yii\db\Expression(
                    'MATCH([[normalizedWords]]) AGAINST (' . $param . ' IN BOOLEAN MODE)',
                    [$param => $mode === self::MODE_WORD_START ? $word . '*' : $word]
                );
                continue;
            }

            $escaped = StringHelper::escapeLike($word);
            $pattern = match ($mode) {
                self::MODE_EXACT => "% $escaped %",
                self::MODE_WORD_CONTENT => "%$escaped%",
                default => "% $escaped%",
            };
            $conditions[] = ['like', 'normalizedWords', $pattern, false];
        }

        return array_merge([$or ? 'or' : 'and'], $conditions);
    }

    /**
     * Returns the found index rows grouped by element.
     */
    protected function getRowsGroupedByElements(Query $dbQuery): array
    {
        $grouped = [];

        foreach ($dbQuery->all() as $row) {
            $elementId = (int)$row['elementId'];

            if (!isset($grouped[$elementId])) {
                $grouped[$elementId] = [
                    'elementId' => $elementId,
                    'type' => $row['type'],
                    'rows' => [],
                ];
            }

            $row['elementId'] = $elementId;
            $row['fieldId'] = $row['fieldId'] !== null ? (int)$row['fieldId'] : null;
            $grouped[$elementId]['rows'][] = $row;
        }

        return array_values($grouped);
    }

    /**
     * Keeps the elements that contain every query word in at least one of their rows.
     */
    protected function filterElementRowsContainingAllWords(array $elementRows, string $normalizedQuery, int $mode): array
    {
        $words = array_unique(explode(' ', $normalizedQuery));

        if (count($words) < 2) {
            return $elementRows;
        }

        $needles = array_map(fn($word) => match ($mode) {
            self::MODE_EXACT => " $word ",
            self::MODE_WORD_CONTENT => $word,
            default => " $word",
        }, $words);

        return array_values(array_filter($elementRows, function($elementRow) use ($needles) {
            foreach ($needles as $needle) {
                $found = false;

                foreach ($elementRow['rows'] as $row) {
                    if (str_contains((string)$row['normalizedWords'], $needle)) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Removes rows of elements that shouldn't be shown (e.g. disabled or expired ones).
     *
     * @return array<string,ElementQueryInterface> Element queries by element type
     */
    protected function filterElementRows(array &$elementRows, array $config): array
    {
        $idsByType = [];

        foreach ($elementRows as $elementRow) {
            $idsByType[$elementRow['type']][] = $elementRow['elementId'];
        }

        [$validIds, $queries] = $this->filterElementIds($idsByType, $config);
        $elementRows = array_values(array_filter($elementRows, fn($row) => isset($validIds[$row['elementId']])));

        return $queries;
    }

    /**
     * Returns the ids of the given elements that should be shown (e.g. not disabled or expired).
     *
     * @param array<string,int[]> $idsByType Element ids grouped by element type
     * @return array{0: array<int,true>, 1: array<string,ElementQueryInterface>} Valid ids (as keys) and the element queries by type
     */
    public function filterElementIds(array $idsByType, array $config): array
    {
        $queries = [];
        $validIds = [];

        foreach ($idsByType as $type => $ids) {
            if (!class_exists($type) || !is_subclass_of($type, ElementInterface::class)) {
                continue;
            }

            $queries[$type] = $this->createElementQuery($type, $config);

            foreach ((clone $queries[$type])->id(array_values(array_unique($ids)))->ids() as $id) {
                $validIds[(int)$id] = true;
            }
        }

        return [$validIds, $queries];
    }

    /**
     * @param class-string<ElementInterface> $type
     */
    protected function createElementQuery(string $type, array $config): ElementQueryInterface
    {
        $query = $type::find()->siteId($config['siteId']);

        // null is a valid status (any status), so no ?? here
        if (array_key_exists('status', $config) && $config['status'] !== 'default') {
            $query->status($config['status']);
        }

        if ($this->hasEventHandlers(self::EVENT_MODIFY_ELEMENT_QUERY)) {
            $event = new ElementQueryEvent(['elementType' => $type, 'query' => $query, 'config' => $config]);
            $this->trigger(self::EVENT_MODIFY_ELEMENT_QUERY, $event);
            $query = $event->query;
        }

        return $query;
    }

    /**
     * Fetches the elements of the given rows and adds them to the rows (keeps the order).
     *
     * @param array<string,ElementQueryInterface> $elementQueries
     */
    protected function determineResults(array $pageRows, array $elementQueries): array
    {
        $idsByType = [];

        foreach ($pageRows as $row) {
            if (isset($row['elementId'], $elementQueries[$row['type'] ?? ''])) {
                $idsByType[$row['type']][] = $row['elementId'];
            }
        }

        $elements = [];

        foreach ($idsByType as $type => $ids) {
            foreach ((clone $elementQueries[$type])->id($ids)->all() as $element) {
                $elements[$element->id] = $element;
            }
        }

        $results = [];

        foreach ($pageRows as $row) {
            // rows without element (e.g. added by an event) are kept as they are
            if (!isset($row['elementId'])) {
                $results[] = $row;
                continue;
            }

            $element = $elements[$row['elementId']] ?? null;

            if (!$element) {
                continue;
            }

            $row['element'] = $element;
            $row['title'] = $element::hasTitles() ? (string)$element->title : (string)$element;
            $row['slug'] = $element->slug;
            $row['uri'] = $element->uri;
            $row['url'] = $element->getUrl();
            $results[] = $row;
        }

        return $results;
    }

    protected function addSnippets(array &$results, string $normalizedQuery, array $config): void
    {
        $extractConfig = $config['extract'];

        foreach ($results as $key => $result) {
            if (!isset($result['rows'])) {
                continue;
            }

            $wordsFound = 0;
            $extracts = [];

            foreach ($result['rows'] as $rowKey => $row) {
                if (in_array($row['attribute'], ['title', 'slug'], true)) {
                    continue;
                }

                // the index text is plain text already, html must not be stripped twice
                $extractor = new WordRadiusExtractor(
                    (string)$row['text'],
                    $normalizedQuery,
                    (string)$extractConfig['wrap'],
                    $config['mode'],
                    $extractConfig['radius'],
                    $extractConfig['limit'],
                    false,
                );
                $extractor->extract();

                $results[$key]['rows'][$rowKey]['snippets'] = [
                    'wordsFound' => $extractor->getWordsFound(),
                    'extracts' => $extractor->getExtracts(),
                ];

                $wordsFound += $extractor->getWordsFound();
                array_push($extracts, ...$extractor->getExtracts());
            }

            // aggregated over all rows of the element, handy for simple templates
            $extracts = array_slice($extracts, 0, $extractConfig['limit']);

            foreach ($extracts as $i => $extract) {
                $extracts[$i]['nr'] = $i + 1;
            }

            $results[$key]['wordsFound'] = $wordsFound;
            $results[$key]['extracts'] = $extracts;
        }
    }

    protected function createPagination(int $total, ?int $page, int $resultsPerPage): Paginate
    {
        $totalPages = max(1, (int)ceil($total / $resultsPerPage));

        if ($page === null) {
            $request = Craft::$app->getRequest();
            $page = !$request->getIsConsoleRequest() ? $request->getPageNum() : 1;
        }

        $currentPage = min(max(1, (int)$page), $totalPages);
        $first = $total > 0 ? ($currentPage - 1) * $resultsPerPage + 1 : 0;

        return new Paginate([
            'first' => $first,
            'last' => $total > 0 ? min($first + $resultsPerPage - 1, $total) : 0,
            'total' => $total,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
        ]);
    }
}
