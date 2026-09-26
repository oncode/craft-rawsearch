<?php

namespace oncode\rawsearch\services;

use Craft;
use craft\base\Component;
use craft\base\ElementContainerFieldInterface;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\base\NestedElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\ElementCollection;
use craft\fields\BaseRelationField;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\Queue;
use craft\helpers\StringHelper as CraftStringHelper;
use oncode\rawsearch\db\Table;
use oncode\rawsearch\events\IndexElementEvent;
use oncode\rawsearch\helpers\IndexHelper;
use oncode\rawsearch\helpers\StringHelper;
use oncode\rawsearch\jobs\IndexElements;
use oncode\rawsearch\jobs\IndexElementType;
use oncode\rawsearch\RawSearch;

/**
 * Builds the search index.
 */
class Index extends Component
{
    /** Fired before an element gets indexed, set `$event->isValid = false` to skip it. */
    public const EVENT_BEFORE_INDEX_ELEMENT = 'beforeIndexElement';

    /** Fired after an element has been indexed. */
    public const EVENT_AFTER_INDEX_ELEMENT = 'afterIndexElement';

    /** Allows modifying the attribute values that get indexed (`$event->attributeValues`). */
    public const EVENT_MODIFY_ATTRIBUTE_VALUES = 'modifyAttributeValues';

    /** Allows modifying the field values that get indexed (`$event->fieldValues`). */
    public const EVENT_MODIFY_FIELD_VALUES = 'modifyFieldValues';

    /** Allows modifying the rows that get inserted into the index table (`$event->rows`). */
    public const EVENT_MODIFY_ROWS = 'modifyRows';

    /** Nested elements (e.g. Matrix entries) deeper than this are ignored. */
    public int $maxNestingLevel = 10;

    /** @var array<string,int[]> Element ids to reindex at the end of the request, grouped by type */
    private array $queuedElementIds = [];

    public function morePowerPls(): void
    {
        // with great memory comes great responsibility
        @ini_set('memory_limit', RawSearch::getInstance()->getSettings()->memoryLimit);
        @set_time_limit(0);
    }

    public function isWhitelistedFieldType(FieldInterface $field): bool
    {
        return in_array(get_class($field), RawSearch::getInstance()->getSettings()->whitelistedFieldTypes, true);
    }

    /**
     * Returns all fields whose field type is indexed.
     *
     * @return FieldInterface[]
     */
    public function getIndexableFields(): array
    {
        return array_values(array_filter(
            Craft::$app->getFields()->getAllFields(),
            fn(FieldInterface $field) => $this->isWhitelistedFieldType($field)
        ));
    }

    /**
     * Whether the field contains nested elements whose fields should be indexed (Matrix, Neo, ...).
     */
    public function isContainerField(FieldInterface $field): bool
    {
        return $field instanceof ElementContainerFieldInterface
            || in_array(get_class($field), ['benf\neo\Field', 'verbb\supertable\fields\SuperTableField'], true);
    }

    /**
     * Whether an element is indexed on its own. Nested elements are indexed as part of their owner.
     */
    public function isIndexableElement(ElementInterface $element): bool
    {
        if ($element instanceof NestedElementInterface && $element->getPrimaryOwnerId()) {
            return false;
        }

        return RawSearch::getInstance()->elementTypeConfigs->isIndexed(get_class($element));
    }

    public function removeAll(): void
    {
        Db::truncateTable(Table::INDEX);
    }

    /**
     * @param int[] $elementIds
     */
    public function removeByElementIds(array $elementIds, ?int $siteId = null): void
    {
        if (empty($elementIds)) {
            return;
        }

        $condition = ['elementId' => $elementIds];

        if ($siteId) {
            $condition['siteId'] = $siteId;
        }

        Db::delete(Table::INDEX, $condition);
    }

    public function removeByElementType(string $elementType): void
    {
        Db::delete(Table::INDEX, ['type' => $elementType]);
    }

    /**
     * Removes rows of element types that are not indexed (anymore).
     */
    public function removeNotIndexedElementTypes(): void
    {
        $allTypes = RawSearch::getInstance()->elementTypeConfigs->getAllElementTypes();
        $blacklisted = RawSearch::getInstance()->elementTypeConfigs->getBlacklistedElementTypes();

        Db::delete(Table::INDEX, ['or', ['type' => $blacklisted], ['not', ['type' => $allTypes]]]);
    }

    /**
     * Indexes an element in the element's site. Existing index rows of the element get replaced.
     */
    public function indexElement(ElementInterface $element): bool
    {
        if (!$element->id || !$element->siteId) {
            return false;
        }

        $this->removeByElementIds([$element->id], $element->siteId);

        if (
            ElementHelper::isDraftOrRevision($element)
            || $element->trashed
            || $element->archived
            || !$element->enabled
            || !$element->getEnabledForSite()
            || !$this->isIndexableElement($element)
        ) {
            return false;
        }

        $event = new IndexElementEvent(['element' => $element]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_INDEX_ELEMENT)) {
            $this->trigger(self::EVENT_BEFORE_INDEX_ELEMENT, $event);

            if (!$event->isValid) {
                return false;
            }
        }

        $event->attributeValues = $this->getAttributeValues($element);

        if ($this->hasEventHandlers(self::EVENT_MODIFY_ATTRIBUTE_VALUES)) {
            $this->trigger(self::EVENT_MODIFY_ATTRIBUTE_VALUES, $event);
        }

        $event->fieldValues = $this->getFieldValues($element);

        if ($this->hasEventHandlers(self::EVENT_MODIFY_FIELD_VALUES)) {
            $this->trigger(self::EVENT_MODIFY_FIELD_VALUES, $event);
        }

        $event->rows = $this->getIndexRows($element, $event->attributeValues, $event->fieldValues);

        if ($this->hasEventHandlers(self::EVENT_MODIFY_ROWS)) {
            $this->trigger(self::EVENT_MODIFY_ROWS, $event);
        }

        $this->saveIndexRows($event->rows);

        if ($this->hasEventHandlers(self::EVENT_AFTER_INDEX_ELEMENT)) {
            $this->trigger(self::EVENT_AFTER_INDEX_ELEMENT, $event);
        }

        return true;
    }

    /**
     * @return array<string,string>
     */
    protected function getAttributeValues(ElementInterface $element): array
    {
        $values = [];

        foreach (ElementHelper::searchableAttributes($element) as $attribute) {
            try {
                $values[$attribute] = CraftStringHelper::toString($element->$attribute ?? '', ' ');
            } catch (\Throwable $e) {
                Craft::warning("Could not index attribute $attribute of element $element->id: " . $e->getMessage(), 'rawsearch');
            }
        }

        // temp slugs of unsaved elements are meaningless
        if (isset($values['slug']) && ElementHelper::isTempSlug($values['slug'])) {
            unset($values['slug']);
        }

        return $values;
    }

    /**
     * Returns the values of all indexed fields, including the ones of nested elements.
     *
     * @return array List of `id`, `type`, `handle`, `value`
     */
    protected function getFieldValues(ElementInterface $element, int $level = 0): array
    {
        $fieldLayout = $element->getFieldLayout();

        if (!$fieldLayout || $level > $this->maxNestingLevel) {
            return [];
        }

        $fieldConfigs = RawSearch::getInstance()->fieldConfigs;
        $values = [];

        foreach ($fieldLayout->getCustomFields() as $field) {
            if (!$this->isWhitelistedFieldType($field) || !$fieldConfigs->isIndexed($field->id)) {
                continue;
            }

            $value = $element->getFieldValue($field->handle);

            if ($this->isContainerField($field) && !$field instanceof BaseRelationField) {
                foreach ($this->getNestedElements($value) as $nestedElement) {
                    array_push($values, ...$this->getFieldValues($nestedElement, $level + 1));
                }

                continue;
            }

            try {
                $keywords = $field->getSearchKeywords($value, $element);
            } catch (\Throwable $e) {
                Craft::warning("Could not index field $field->handle of element $element->id: " . $e->getMessage(), 'rawsearch');
                continue;
            }

            $values[] = [
                'id' => $field->id,
                'type' => get_class($field),
                'handle' => $field->handle,
                'value' => $keywords,
            ];
        }

        return $values;
    }

    /**
     * @return ElementInterface[]
     */
    protected function getNestedElements(mixed $value): iterable
    {
        if ($value instanceof ElementQueryInterface) {
            return (clone $value)->all();
        }

        if ($value instanceof ElementCollection || is_array($value)) {
            return array_filter(is_array($value) ? $value : $value->all(), fn($v) => $v instanceof ElementInterface);
        }

        return $value instanceof ElementInterface ? [$value] : [];
    }

    protected function getIndexRows(ElementInterface $element, array $attributeValues, array $fieldValues): array
    {
        $rows = [];
        $blacklistedWords = $this->getBlacklistedWords();
        $now = Db::prepareDateForDb(new \DateTime());
        $base = [
            'elementId' => (int)$element->id,
            'siteId' => (int)$element->siteId,
            'type' => get_class($element),
        ];

        $values = [];

        foreach ($attributeValues as $attribute => $value) {
            $values[] = [$attribute, null, $value];
        }

        foreach ($fieldValues as $fieldValue) {
            $values[] = ['field', (int)$fieldValue['id'], $fieldValue['value']];
        }

        foreach ($values as [$attribute, $fieldId, $value]) {
            $value = (string)$value;
            $normalizedWords = StringHelper::normalize($value, $blacklistedWords);

            if ($normalizedWords === '') {
                continue;
            }

            $rows[] = $base + [
                'attribute' => $attribute,
                'fieldId' => $fieldId,
                // surrounding spaces allow LIKE searches for word starts
                'normalizedWords' => ' ' . IndexHelper::truncate($normalizedWords) . ' ',
                'text' => IndexHelper::truncate(IndexHelper::prepareText($value)),
                'dateIndexed' => $now,
            ];
        }

        return $rows;
    }

    public function saveIndexRows(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $columns = ['elementId', 'siteId', 'type', 'attribute', 'fieldId', 'normalizedWords', 'text', 'dateIndexed'];
        $values = array_map(fn($row) => array_map(fn($column) => $row[$column] ?? null, $columns), $rows);

        Db::batchInsert(Table::INDEX, $columns, $values);
    }

    /**
     * @return string[]
     */
    public function getBlacklistedWords(): array
    {
        return RawSearch::getInstance()->getSettings()->getBlacklistedWordList();
    }

    /**
     * Returns the element that is indexed for the given element (the root owner for nested elements).
     */
    public function getIndexedElement(ElementInterface $element): ?ElementInterface
    {
        $level = 0;

        while ($element instanceof NestedElementInterface && $element->getPrimaryOwnerId()) {
            $owner = $element->getPrimaryOwner();

            if (!$owner || ++$level > $this->maxNestingLevel) {
                return null;
            }

            $element = $owner;
        }

        return $element;
    }

    /**
     * Remembers an element to reindex at the end of the request.
     * Collecting them prevents a queue job per saved element (e.g. when resaving all entries).
     */
    public function queueElement(ElementInterface $element): void
    {
        if (ElementHelper::isDraftOrRevision($element)) {
            return;
        }

        $element = $this->getIndexedElement($element);

        if (!$element || !$element->id || ElementHelper::isDraftOrRevision($element)) {
            return;
        }

        $type = get_class($element);

        if (!RawSearch::getInstance()->elementTypeConfigs->isIndexed($type)) {
            return;
        }

        $this->queuedElementIds[$type][$element->id] = $element->id;
    }

    public function pushQueuedElements(): void
    {
        foreach ($this->queuedElementIds as $type => $ids) {
            Queue::push(new IndexElements([
                'elementType' => $type,
                'elementIds' => array_values($ids),
            ]));
        }

        $this->queuedElementIds = [];
    }

    /**
     * Pushes a job that reindexes all elements of the given type.
     */
    public function queueElementType(string $elementType): void
    {
        Queue::push(new IndexElementType(['elementType' => $elementType]));
    }

    /**
     * Pushes jobs that reindex all indexed element types and removes the rest from the index.
     */
    public function queueAll(): void
    {
        $this->removeNotIndexedElementTypes();

        foreach ($this->getIndexedElementTypes() as $elementType) {
            $this->queueElementType($elementType);
        }
    }

    /**
     * @return string[]
     */
    public function getIndexedElementTypes(): array
    {
        $configs = RawSearch::getInstance()->elementTypeConfigs;

        return array_values(array_filter($configs->getAllElementTypes(), fn($type) => $configs->isIndexed($type)));
    }

    /**
     * Returns the query for all elements of a type in all sites that should be indexed.
     *
     * @param class-string<ElementInterface> $elementType
     */
    public function createElementTypeQuery(string $elementType): ElementQueryInterface
    {
        return $elementType::find()
            ->site('*')
            ->unique(false)
            ->status(null)
            ->orderBy(['elements.id' => SORT_ASC]);
    }

    /**
     * Indexes all elements of a type synchronously and removes rows of elements that weren't indexed again.
     *
     * @param callable|null $onProgress Called with the number of processed and total elements
     */
    public function indexElementType(string $elementType, ?callable $onProgress = null): int
    {
        $this->morePowerPls();
        $start = Db::prepareDateForDb(new \DateTime());
        $query = $this->createElementTypeQuery($elementType);
        $total = $query->count();
        $done = 0;

        foreach (Db::each($query) as $element) {
            $this->indexElement($element);
            $done++;

            if ($onProgress) {
                $onProgress($done, $total);
            }
        }

        $this->removeStaleRows($elementType, $start);

        return $done;
    }

    /**
     * Removes rows of the element type that have been indexed before the given date.
     */
    public function removeStaleRows(string $elementType, string $before): void
    {
        Db::delete(Table::INDEX, ['and', ['type' => $elementType], ['<', 'dateIndexed', $before]]);
    }
}
