<?php

namespace oncode\rawsearch\models;

use craft\base\Model;

/**
 * Plugin settings.
 *
 * Every property can be overridden with a `config/rawsearch.php` file.
 */
class Settings extends Model
{
    /** Name of the plugin in the control panel navigation. */
    public string $name = 'Search';

    /** Whether search queries are stored for the statistic. */
    public bool $statistic = true;

    /** Key that allows anonymous access to the critical API actions (reindexing, query statistic). */
    public string $apiKey = '';

    /** @var string[] Field type classes whose values get indexed. */
    public array $whitelistedFieldTypes = [
        'craft\fields\PlainText',
        'craft\fields\Table',
        'craft\fields\Matrix',
        'craft\ckeditor\Field',
        'craft\redactor\Field',
        'benf\neo\Field',
        'verbb\supertable\fields\SuperTableField',
    ];

    /** Comma separated list of words that won't be indexed. */
    public string $blacklistedWords = '';

    public int $titleMatchWeight = 100;
    public int $partialTitleMatchWeight = 50;
    public int $fieldMatchWeight = 10;
    public int $partialFieldMatchWeight = 5;
    public int $elementTypeMatchWeight = 1000;

    /** Memory limit used for heavy tasks like building the search index. */
    public string $memoryLimit = '512M';

    /** Maximum number of index rows fetched for a search (null = no limit). */
    public ?int $rowLimitSearch = null;

    /** Maximum number of index rows fetched for an autocomplete search (null = no limit). */
    public ?int $rowLimitAutocompleteSearch = null;

    public function setAttributes($values, $safeOnly = true): void
    {
        // posted lightswitch lists may come in as empty strings
        if (isset($values['whitelistedFieldTypes']) && !is_array($values['whitelistedFieldTypes'])) {
            $values['whitelistedFieldTypes'] = array_filter(array_map('trim', explode(',', (string)$values['whitelistedFieldTypes'])));
        }

        parent::setAttributes($values, $safeOnly);
    }

    protected function defineRules(): array
    {
        return [
            [['name', 'apiKey'], 'required'],
            [['name', 'apiKey', 'blacklistedWords', 'memoryLimit'], 'string'],
            [['statistic'], 'boolean'],
            [[
                'titleMatchWeight',
                'partialTitleMatchWeight',
                'fieldMatchWeight',
                'partialFieldMatchWeight',
                'elementTypeMatchWeight',
            ], 'integer', 'min' => -32768, 'max' => 32767],
            [['rowLimitSearch', 'rowLimitAutocompleteSearch'], 'integer', 'min' => 1],
            [['whitelistedFieldTypes'], 'each', 'rule' => ['string']],
        ];
    }

    /**
     * @return string[]
     */
    public function getBlacklistedWordList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->blacklistedWords))));
    }
}
