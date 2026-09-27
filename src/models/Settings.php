<?php

namespace oncode\rawsearch\models;

use CraftCms\Cms\Plugin\PluginSettings;

/**
 * Plugin settings.
 *
 * Every property can be overridden with a `config/craft/rawsearch.php` file.
 */
class Settings extends PluginSettings
{
    /** Name of the plugin in the control panel navigation. */
    public string $name = 'Search';

    /** Whether search queries are stored for the statistic. */
    public bool $statistic = true;

    /** Key that allows anonymous access to the critical API actions (reindexing, query statistic). */
    public string $apiKey = '';

    /** @var string[] Field type classes whose values get indexed. */
    public array $whitelistedFieldTypes = [
        'CraftCms\Cms\Field\PlainText',
        'CraftCms\Cms\Field\Table',
        'CraftCms\Cms\Field\Matrix',
        'craft\ckeditor\Field',
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

    public function setAttributes($values): void
    {
        // posted lightswitch lists may come in as empty strings
        if (isset($values['whitelistedFieldTypes']) && !is_array($values['whitelistedFieldTypes'])) {
            $values['whitelistedFieldTypes'] = array_filter(array_map('trim', explode(',', (string)$values['whitelistedFieldTypes'])));
        }

        parent::setAttributes($values);
    }

    public function getRules(): array
    {
        $weight = ['required', 'integer', 'min:-32768', 'max:32767'];

        return [
            'name' => ['required', 'string'],
            'apiKey' => ['required', 'string'],
            'blacklistedWords' => ['nullable', 'string'],
            'memoryLimit' => ['required', 'string'],
            'statistic' => ['boolean'],
            'titleMatchWeight' => $weight,
            'partialTitleMatchWeight' => $weight,
            'fieldMatchWeight' => $weight,
            'partialFieldMatchWeight' => $weight,
            'elementTypeMatchWeight' => $weight,
            'rowLimitSearch' => ['nullable', 'integer', 'min:1'],
            'rowLimitAutocompleteSearch' => ['nullable', 'integer', 'min:1'],
            'whitelistedFieldTypes' => ['array'],
            'whitelistedFieldTypes.*' => ['string'],
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
