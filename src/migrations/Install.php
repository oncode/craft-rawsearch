<?php

namespace oncode\rawsearch\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Table as CraftTable;
use oncode\rawsearch\db\Table;
use oncode\rawsearch\services\ElementTypeConfigs;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable(Table::INDEX, [
            'id' => $this->primaryKey(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'type' => $this->string()->notNull(),
            'attribute' => $this->string(50),
            'fieldId' => $this->integer(),
            'normalizedWords' => $this->mediumText()->notNull(),
            'text' => $this->mediumText()->notNull(),
            'dateIndexed' => $this->dateTime()->notNull(),
        ]);
        $this->createIndex(null, Table::INDEX, ['elementId', 'siteId']);
        $this->createIndex(null, Table::INDEX, ['siteId', 'type']);
        $this->addForeignKey(null, Table::INDEX, ['elementId'], CraftTable::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::INDEX, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', 'CASCADE');

        if ($this->db->getIsMysql()) {
            // The stopword list is bound to the fulltext index when it gets created.
            // Without stopwords, common words like "about" or "und" stay searchable.
            $this->execute('SET SESSION innodb_ft_enable_stopword = 0');
            $this->execute(sprintf(
                'CREATE FULLTEXT INDEX %s ON %s (%s)',
                $this->db->quoteTableName($this->db->getIndexName()),
                $this->db->quoteTableName(Table::INDEX),
                $this->db->quoteColumnName('normalizedWords'),
            ));
        }

        $this->createTable(Table::ELEMENT_TYPE_CONFIGS, [
            'id' => $this->primaryKey(),
            'type' => $this->string()->notNull(),
            'index' => $this->boolean()->notNull()->defaultValue(true),
            'matchWeight' => $this->smallInteger(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, Table::ELEMENT_TYPE_CONFIGS, ['type'], true);

        $this->createTable(Table::FIELD_CONFIGS, [
            'id' => $this->primaryKey(),
            'fieldId' => $this->integer()->notNull(),
            'index' => $this->boolean()->notNull()->defaultValue(true),
            'matchWeight' => $this->smallInteger(),
            'partialMatchWeight' => $this->smallInteger(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, Table::FIELD_CONFIGS, ['fieldId'], true);
        $this->addForeignKey(null, Table::FIELD_CONFIGS, ['fieldId'], CraftTable::FIELDS, ['id'], 'CASCADE');

        $this->createTable(Table::QUERIES, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'query' => $this->string()->notNull(),
            'or' => $this->boolean()->notNull()->defaultValue(false),
            'mode' => $this->smallInteger()->notNull(),
            'results' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, Table::QUERIES, ['siteId', 'query']);
        $this->createIndex(null, Table::QUERIES, ['dateCreated']);
        $this->addForeignKey(null, Table::QUERIES, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', 'CASCADE');

        foreach (ElementTypeConfigs::DEFAULT_BLACKLISTED as $type) {
            $this->insert(Table::ELEMENT_TYPE_CONFIGS, ['type' => $type, 'index' => false]);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::QUERIES);
        $this->dropTableIfExists(Table::FIELD_CONFIGS);
        $this->dropTableIfExists(Table::ELEMENT_TYPE_CONFIGS);
        $this->dropTableIfExists(Table::INDEX);

        return true;
    }
}
