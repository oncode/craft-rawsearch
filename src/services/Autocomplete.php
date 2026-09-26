<?php

namespace oncode\rawsearch\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use oncode\rawsearch\db\Table;
use oncode\rawsearch\events\AutocompleteWordElementEvent;
use oncode\rawsearch\events\DbQueryEvent;
use oncode\rawsearch\events\RowsEvent;
use oncode\rawsearch\events\SearchEvent;
use oncode\rawsearch\helpers\StringHelper;
use oncode\rawsearch\RawSearch;
use yii\base\InvalidArgumentException;

/**
 * Completes a word start with the words found in the index.
 */
class Autocomplete extends Component
{
    public const EVENT_BEFORE_AUTOCOMPLETE = 'beforeAutocomplete';
    public const EVENT_AFTER_AUTOCOMPLETE = 'afterAutocomplete';

    /** Allows modifying the db query, e.g. to join more data for the word element data event. */
    public const EVENT_MODIFY_AUTOCOMPLETE_QUERY = 'modifyAutocompleteQuery';

    /** Allows modifying the data of an element related to a word. */
    public const EVENT_MODIFY_WORD_ELEMENT_DATA = 'modifyWordElementData';

    /** Allows modifying (e.g. resorting) the found words. */
    public const EVENT_MODIFY_TERMS = 'modifyTerms';

    public array $defaultConfig = [
        'site' => null,
        'elementTypes' => null,
        // status passed to the element queries, see Search::$defaultConfig
        'status' => 'default',
        // max number of words, the most frequent ones are kept (null = no limit)
        'limit' => null,
    ];

    /**
     * Returns the words starting with the given query, sorted alphabetically.
     *
     * @return array List of `word` and `elements` (`id`, `type`, `count`)
     */
    public function search(array $params): array
    {
        $query = trim((string)($params['query'] ?? ''));

        if ($query === '') {
            throw new InvalidArgumentException('No query given');
        }

        $search = RawSearch::getInstance()->search;
        $config = array_replace_recursive($this->defaultConfig, $params);
        $site = $search->resolveSite($config['site']);
        $config['site'] = $site->handle;
        $config['siteId'] = $site->id;
        $config['elementTypes'] = $search->resolveElementTypes($config['elementTypes']);

        // the typed text is completed as it is, but blacklisted words are not in the index
        $normalizedQuery = StringHelper::normalize($query);
        $indexQuery = $search->normalizeQuery($query);

        if ($normalizedQuery === '' || $indexQuery === '') {
            return [];
        }

        $dbQuery = $this->buildSearchQuery($indexQuery, $config);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_AUTOCOMPLETE)) {
            $this->trigger(self::EVENT_BEFORE_AUTOCOMPLETE, new SearchEvent([
                'query' => $query,
                'normalizedQuery' => $normalizedQuery,
                'config' => $config,
                'dbQuery' => $dbQuery,
            ]));
        }

        $words = $this->getWordsFromQuery($this->getLiveRows($dbQuery, $config), $normalizedQuery);
        $words = $this->addResultCounts($words);

        if ($config['limit'] !== null && (int)$config['limit'] > 0 && count($words) > (int)$config['limit']) {
            $words = $this->limitWords($words, (int)$config['limit']);
        }

        $words = $this->addPhraseResultCounts($words, $config);

        if ($this->hasEventHandlers(self::EVENT_MODIFY_TERMS)) {
            $event = new RowsEvent(['rows' => $words, 'normalizedQuery' => $normalizedQuery, 'config' => $config]);
            $this->trigger(self::EVENT_MODIFY_TERMS, $event);
            $words = $event->rows;
        }

        if ($this->hasEventHandlers(self::EVENT_AFTER_AUTOCOMPLETE)) {
            $this->trigger(self::EVENT_AFTER_AUTOCOMPLETE, new SearchEvent([
                'query' => $query,
                'normalizedQuery' => $normalizedQuery,
                'config' => $config,
                'dbQuery' => $dbQuery,
                'words' => $words,
            ]));
        }

        return $words;
    }

    public function buildSearchQuery(string $normalizedQuery, array $config): Query
    {
        $dbQuery = (new Query())
            ->select(['rawsearch.elementId', 'rawsearch.type', 'rawsearch.text'])
            ->from(['rawsearch' => Table::INDEX])
            ->where(['rawsearch.siteId' => $config['siteId']])
            // slugs repeat the title in lowercase and would distort the spelling of the words
            ->andWhere(['not', ['rawsearch.attribute' => 'slug']]);

        // the query is completed as a whole, so all words have to be there
        $words = explode(' ', $normalizedQuery);
        $dbQuery->andWhere(RawSearch::getInstance()->search->buildWordsCondition($words, false, Search::MODE_WORD_START));

        if ($config['elementTypes']) {
            $dbQuery->andWhere(['rawsearch.type' => $config['elementTypes']]);
        }

        $limit = RawSearch::getInstance()->getSettings()->rowLimitAutocompleteSearch;

        if ($limit) {
            $dbQuery->limit($limit);
        }

        if ($this->hasEventHandlers(self::EVENT_MODIFY_AUTOCOMPLETE_QUERY)) {
            $this->trigger(self::EVENT_MODIFY_AUTOCOMPLETE_QUERY, new DbQueryEvent([
                'dbQuery' => $dbQuery,
                'normalizedQuery' => $normalizedQuery,
                'config' => $config,
            ]));
        }

        return $dbQuery;
    }

    /**
     * Adds `results`: the number of elements a search for the word finds (the search matches word starts,
     * so "environment" also finds elements that only contain "environmental").
     * The words were found by the same word start, so all elements are known already.
     */
    protected function addResultCounts(array $words): array
    {
        $normalized = array_map(fn($word) => StringHelper::normalize($word['word']), $words);

        foreach ($words as $i => $word) {
            if (str_contains($normalized[$i], ' ')) {
                continue;
            }

            $ids = [];

            foreach ($normalized as $j => $other) {
                if (str_starts_with($other, $normalized[$i]) && !str_contains($other, ' ')) {
                    foreach ($words[$j]['elements'] as $element) {
                        $ids[$element['id']] = true;
                    }
                }
            }

            $words[$i]['results'] = count($ids);
        }

        return $words;
    }

    /**
     * Adds `results` to phrases, the search matches their words anywhere in an element, so it has to be counted.
     */
    protected function addPhraseResultCounts(array $words, array $config): array
    {
        foreach ($words as $i => $word) {
            if (!isset($word['results'])) {
                $words[$i]['results'] = RawSearch::getInstance()->search->countResults([
                    'query' => $word['word'],
                    'site' => $config['siteId'],
                    'elementTypes' => $config['elementTypes'],
                    'status' => $config['status'],
                ]);
            }
        }

        return $words;
    }

    /**
     * Keeps the most frequent words and their alphabetical order.
     */
    protected function limitWords(array $words, int $limit): array
    {
        $frequency = array_map(fn($word) => array_sum(array_column($word['elements'], 'count')), $words);
        arsort($frequency);
        $keep = array_slice(array_keys($frequency), 0, $limit, true);
        sort($keep);

        return array_map(fn($key) => $words[$key], $keep);
    }

    /**
     * Returns the found rows of elements that may be shown, words of e.g. disabled or scheduled entries must not leak.
     */
    protected function getLiveRows(Query $dbQuery, array $config): array
    {
        $rows = $dbQuery->all();
        $idsByType = [];

        foreach ($rows as $row) {
            $idsByType[$row['type']][] = (int)$row['elementId'];
        }

        [$validIds] = RawSearch::getInstance()->search->filterElementIds($idsByType, $config);

        return array_filter($rows, fn($row) => isset($validIds[(int)$row['elementId']]));
    }

    /**
     * Finds the original words (with diacritics) whose normalized form starts with the query.
     * Words that only differ in case are merged, the most frequent spelling is used.
     */
    protected function getWordsFromQuery(array $rows, string $normalizedQuery): array
    {
        $words = [];
        $spellings = [];
        $queryWordCount = count(explode(' ', $normalizedQuery));
        // blacklisted words are not indexed, a search for them finds nothing
        $blacklisted = array_flip(array_map(
            fn($word) => StringHelper::normalize($word),
            RawSearch::getInstance()->index->getBlacklistedWords()
        ));

        foreach ($rows as $row) {
            $elementId = (int)$row['elementId'];
            $tokens = preg_split('/\s+/u', trim(StringHelper::removePunctuation((string)$row['text'])), -1, PREG_SPLIT_NO_EMPTY);
            $normalizedTokens = array_map(fn($token) => StringHelper::normalize($token), $tokens);
            $tokenCount = count($tokens);

            for ($i = 0; $i <= $tokenCount - $queryWordCount; $i++) {
                $candidate = implode(' ', array_slice($normalizedTokens, $i, $queryWordCount));

                if (!str_starts_with($candidate, $normalizedQuery)) {
                    continue;
                }

                // the completed word must be searchable
                if (isset($blacklisted[$normalizedTokens[$i + $queryWordCount - 1]])) {
                    continue;
                }

                $spelling = implode(' ', array_slice($tokens, $i, $queryWordCount));
                $word = mb_strtolower($spelling);
                $spellings[$word][$spelling] = ($spellings[$word][$spelling] ?? 0) + 1;

                if (!isset($words[$word])) {
                    $words[$word] = ['word' => $word, 'elements' => []];
                }

                if (isset($words[$word]['elements'][$elementId])) {
                    $words[$word]['elements'][$elementId]['count']++;
                    continue;
                }

                $data = ['id' => $elementId, 'type' => $row['type'], 'count' => 1];

                if ($this->hasEventHandlers(self::EVENT_MODIFY_WORD_ELEMENT_DATA)) {
                    $event = new AutocompleteWordElementEvent(['elementData' => $data, 'row' => $row]);
                    $this->trigger(self::EVENT_MODIFY_WORD_ELEMENT_DATA, $event);
                    $data = $event->elementData;
                }

                $words[$word]['elements'][$elementId] = $data;
            }
        }

        // sort elements by word occurrences and use the most frequent spelling
        foreach ($words as $word => $data) {
            arsort($spellings[$word]);
            $words[$word]['word'] = (string)array_key_first($spellings[$word]);
            $elements = array_values($data['elements']);
            usort($elements, fn($a, $b) => $b['count'] <=> $a['count']);
            $words[$word]['elements'] = $elements;
        }

        uksort($words, fn($a, $b) => strnatcasecmp((string)$a, (string)$b));

        Craft::info(sprintf('Autocomplete for "%s" found %d words', $normalizedQuery, count($words)), 'rawsearch');

        return array_values($words);
    }
}
