<?php

namespace oncode\rawsearch\services;

use oncode\rawsearch\records\FieldConfig;
use oncode\rawsearch\RawSearch;

/**
 * Which fields get indexed and how much they weigh.
 */
class FieldConfigs
{
    /** @var array<int,FieldConfig>|null */
    private ?array $configs = null;

    /**
     * @return array<int,FieldConfig> Configs indexed by field id
     */
    public function getAll(): array
    {
        if ($this->configs === null) {
            $this->configs = [];

            foreach (FieldConfig::query()->get() as $record) {
                $this->configs[(int)$record->fieldId] = $record;
            }
        }

        return $this->configs;
    }

    /**
     * @return int[]
     */
    public function getBlacklistedFieldIds(): array
    {
        return array_keys(array_filter($this->getAll(), fn(FieldConfig $config) => !$config->index));
    }

    public function isIndexed(int $fieldId): bool
    {
        $config = $this->getAll()[$fieldId] ?? null;

        return !$config || $config->index;
    }

    /**
     * @return array<int,array{exact: int|null, partial: int|null}> Individual match weights by field id
     */
    public function getAllMatchWeights(): array
    {
        $weights = [];

        foreach ($this->getAll() as $fieldId => $config) {
            if ($config->index && ($config->matchWeight !== null || $config->partialMatchWeight !== null)) {
                $weights[$fieldId] = [
                    'exact' => $config->matchWeight !== null ? (int)$config->matchWeight : null,
                    'partial' => $config->partialMatchWeight !== null ? (int)$config->partialMatchWeight : null,
                ];
            }
        }

        return $weights;
    }

    public function saveIndex(int $fieldId, bool $index): bool
    {
        $config = $this->getAll()[$fieldId] ?? null;

        return $this->save($fieldId, $index, $config?->matchWeight, $config?->partialMatchWeight);
    }

    public function saveMatchWeights(int $fieldId, int $matchWeight, int $partialMatchWeight): bool
    {
        $settings = RawSearch::getInstance()->getSettings();

        // default weights aren't stored, so changing the defaults affects this field too
        return $this->save(
            $fieldId,
            $this->isIndexed($fieldId),
            $matchWeight === $settings->fieldMatchWeight ? null : $matchWeight,
            $partialMatchWeight === $settings->partialFieldMatchWeight ? null : $partialMatchWeight,
        );
    }

    private function save(int $fieldId, bool $index, ?int $matchWeight, ?int $partialMatchWeight): bool
    {
        $record = FieldConfig::query()->where('fieldId', $fieldId)->first();
        $this->configs = null;

        // nothing differs from the defaults, no need for a record
        if ($index && $matchWeight === null && $partialMatchWeight === null) {
            $record?->delete();
            return true;
        }

        $record ??= new FieldConfig(['fieldId' => $fieldId]);
        $record->index = $index;
        $record->matchWeight = $matchWeight;
        $record->partialMatchWeight = $partialMatchWeight;

        return $record->save();
    }
}
