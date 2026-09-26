<?php

namespace oncode\rawsearch\helpers;

use Craft;

class IndexHelper
{
    private static ?array $fulltextVariables = null;

    /**
     * Whether MySQL fulltext search (MATCH ... AGAINST) is available.
     * On PostgreSQL every word is searched with LIKE conditions.
     */
    public static function supportsFulltext(): bool
    {
        return Craft::$app->getDb()->getIsMysql();
    }

    /**
     * Words shorter than this are not in the fulltext index and have to be searched with LIKE.
     */
    public static function getMinWordLength(): int
    {
        return static::fulltextVariables()['min'];
    }

    /**
     * Words longer than this are not in the fulltext index and have to be searched with LIKE.
     */
    public static function getMaxWordLength(): int
    {
        return static::fulltextVariables()['max'];
    }

    /**
     * Whether the given normalized word can be found via the fulltext index.
     */
    public static function isFulltextWord(string $word): bool
    {
        if (!static::supportsFulltext()) {
            return false;
        }

        $length = mb_strlen($word);

        return $length >= static::getMinWordLength() && $length <= static::getMaxWordLength();
    }

    private static function fulltextVariables(): array
    {
        if (self::$fulltextVariables === null) {
            // InnoDB defaults
            self::$fulltextVariables = ['min' => 3, 'max' => 84];

            if (static::supportsFulltext()) {
                try {
                    $row = Craft::$app->getDb()
                        ->createCommand('SELECT @@innodb_ft_min_token_size AS min, @@innodb_ft_max_token_size AS max')
                        ->queryOne();

                    if ($row) {
                        self::$fulltextVariables = ['min' => (int)$row['min'], 'max' => (int)$row['max']];
                    }
                } catch (\Throwable $e) {
                    Craft::warning('Could not fetch fulltext variables: ' . $e->getMessage(), 'rawsearch');
                }
            }
        }

        return self::$fulltextVariables;
    }

    /**
     * Max bytes stored per index column. The columns are MEDIUMTEXT (16MB),
     * but huge texts would make the snippet generation slow anyway.
     */
    public const MAX_COLUMN_BYTES = 1000000;

    /**
     * Truncates a string so it fits into the index columns, without cutting a word in half.
     */
    public static function truncate(string $str): string
    {
        $maxSize = self::MAX_COLUMN_BYTES;

        if (strlen($str) <= $maxSize) {
            return $str;
        }

        $str = mb_strcut($str, 0, $maxSize);
        $position = mb_strrpos($str, ' ');

        if ($position) {
            $str = mb_substr($str, 0, $position + 1);
        }

        return $str;
    }

    /**
     * Prepares a string for the column that is used to generate snippets (keeps punctuation).
     */
    public static function prepareText(string $str): string
    {
        return trim(StringHelper::collapseWhitespace(StringHelper::stripHtml($str)));
    }
}
