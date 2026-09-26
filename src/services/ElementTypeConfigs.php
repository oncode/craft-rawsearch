<?php

namespace oncode\rawsearch\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use oncode\rawsearch\records\ElementTypeConfig;
use oncode\rawsearch\RawSearch;

/**
 * Which element types get indexed and how much they weigh.
 */
class ElementTypeConfigs extends Component
{
    /** Element types that are not indexed after the installation. Users are excluded to not expose them in a public search. */
    public const DEFAULT_BLACKLISTED = [
        'craft\elements\User',
        'craft\elements\GlobalSet',
        'craft\elements\Tag',
        'craft\elements\Address',
        'craft\elements\ContentBlock',
        'benf\neo\elements\Block',
        'verbb\supertable\elements\SuperTableBlockElement',
    ];

    /** @var array<string,ElementTypeConfig>|null */
    private ?array $configs = null;

    /**
     * @return array<string,ElementTypeConfig> Configs indexed by element type
     */
    public function getAll(): array
    {
        if ($this->configs === null) {
            $this->configs = [];

            foreach (ElementTypeConfig::find()->all() as $record) {
                $this->configs[$record->type] = $record;
            }
        }

        return $this->configs;
    }

    /**
     * @return string[]
     */
    public function getBlacklistedElementTypes(): array
    {
        return array_keys(array_filter($this->getAll(), fn(ElementTypeConfig $config) => !$config->index));
    }

    public function isIndexed(string $elementType): bool
    {
        $config = $this->getAll()[$elementType] ?? null;

        return !$config || $config->index;
    }

    /**
     * @return array<string,int> Individual match weights by element type
     */
    public function getAllMatchWeights(): array
    {
        $weights = [];

        foreach ($this->getAll() as $type => $config) {
            if ($config->index && $config->matchWeight !== null) {
                $weights[$type] = (int)$config->matchWeight;
            }
        }

        return $weights;
    }

    public function getMatchWeight(string $elementType): int
    {
        return $this->getAllMatchWeights()[$elementType] ?? RawSearch::getInstance()->getSettings()->elementTypeMatchWeight;
    }

    /**
     * Returns all element types that can be configured.
     *
     * @return class-string<ElementInterface>[]
     */
    public function getAllElementTypes(): array
    {
        return array_values(array_filter(
            Craft::$app->getElements()->getAllElementTypes(),
            fn($class) => is_subclass_of($class, ElementInterface::class)
        ));
    }

    /**
     * Resolves an element type class from a class name, a ref handle (`entry`) or a short name (`Entry`).
     *
     * @return class-string<ElementInterface>|null
     */
    public function resolveElementType(string $name): ?string
    {
        if ($name === '') {
            return null;
        }

        if (class_exists($name) && is_subclass_of($name, ElementInterface::class)) {
            return ltrim($name, '\\');
        }

        $class = Craft::$app->getElements()->getElementTypeByRefHandle(lcfirst($name));

        if ($class) {
            return $class;
        }

        foreach ($this->getAllElementTypes() as $class) {
            $shortName = substr($class, (int)strrpos($class, '\\') + 1);

            if (strcasecmp($shortName, $name) === 0) {
                return $class;
            }
        }

        return null;
    }

    public function saveIndex(string $elementType, bool $index): bool
    {
        return $this->save($elementType, $index, $this->getAll()[$elementType]->matchWeight ?? null);
    }

    public function saveMatchWeight(string $elementType, int $matchWeight): bool
    {
        // the default weight isn't stored, so changing the default affects this element type too
        $weight = $matchWeight === RawSearch::getInstance()->getSettings()->elementTypeMatchWeight ? null : $matchWeight;

        return $this->save($elementType, $this->isIndexed($elementType), $weight);
    }

    private function save(string $elementType, bool $index, ?int $matchWeight): bool
    {
        $record = ElementTypeConfig::findOne(['type' => $elementType]);

        // nothing differs from the defaults, no need for a record
        if ($index && $matchWeight === null) {
            $record?->delete();
            $this->configs = null;
            return true;
        }

        $record ??= new ElementTypeConfig(['type' => $elementType]);
        $record->index = $index;
        $record->matchWeight = $matchWeight;
        $this->configs = null;

        return $record->save();
    }
}
