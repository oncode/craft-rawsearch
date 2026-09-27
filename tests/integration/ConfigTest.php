<?php

namespace oncode\rawsearch\tests\integration;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\ProjectConfig\ProjectConfig as ProjectConfigService;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\ProjectConfig;
use CraftCms\Cms\User\Elements\User;
use oncode\rawsearch\records\ElementTypeConfig;
use oncode\rawsearch\records\FieldConfig;
use oncode\rawsearch\tests\TestCase;

class ConfigTest extends TestCase
{
    public function testResolveElementType(): void
    {
        $configs = $this->plugin()->elementTypeConfigs;

        $this->assertSame(Entry::class, $configs->resolveElementType('entry'));
        $this->assertSame(Entry::class, $configs->resolveElementType('Entry'));
        $this->assertSame(Entry::class, $configs->resolveElementType(Entry::class));
        $this->assertSame(Entry::class, $configs->resolveElementType('\\' . Entry::class));
        $this->assertSame(Asset::class, $configs->resolveElementType('ASSET'));
        $this->assertNull($configs->resolveElementType('nope'));
        $this->assertNull($configs->resolveElementType(''));
        // existing classes that are not element types
        $this->assertNull($configs->resolveElementType(\DateTime::class));
    }

    public function testUsersAreNotIndexedByDefault(): void
    {
        $this->assertFalse($this->plugin()->elementTypeConfigs->isIndexed(User::class));
        $this->assertTrue($this->plugin()->elementTypeConfigs->isIndexed(Entry::class));
    }

    public function testElementTypeWeight(): void
    {
        $configs = $this->plugin()->elementTypeConfigs;
        $configs->saveMatchWeight(Entry::class, 500);

        $this->assertSame(500, $configs->getMatchWeight(Entry::class));
        $this->assertNotNull(ElementTypeConfig::query()->where('type', Entry::class)->first());

        // the default weight removes the record
        $configs->saveMatchWeight(Entry::class, $this->plugin()->getSettings()->elementTypeMatchWeight);
        $this->assertNull(ElementTypeConfig::query()->where('type', Entry::class)->first());
    }

    public function testElementTypeIndexKeepsWeight(): void
    {
        $configs = $this->plugin()->elementTypeConfigs;
        $configs->saveMatchWeight(Entry::class, 500);
        $configs->saveIndex(Entry::class, false);

        $this->assertFalse($configs->isIndexed(Entry::class));
        // weights of not indexed types don't matter
        $this->assertArrayNotHasKey(Entry::class, $configs->getAllMatchWeights());

        $configs->saveIndex(Entry::class, true);
        $this->assertSame(500, $configs->getMatchWeight(Entry::class));
    }

    public function testFieldConfigs(): void
    {
        $configs = $this->plugin()->fieldConfigs;
        $field = Fields::getFieldByHandle('rsBody');
        $settings = $this->plugin()->getSettings();

        $configs->saveMatchWeights($field->id, 50, $settings->partialFieldMatchWeight);
        $this->assertSame(['exact' => 50, 'partial' => null], $configs->getAllMatchWeights()[$field->id]);

        $configs->saveIndex($field->id, false);
        $this->assertContains($field->id, $configs->getBlacklistedFieldIds());
        $this->assertArrayNotHasKey($field->id, $configs->getAllMatchWeights());

        $configs->saveIndex($field->id, true);
        $configs->saveMatchWeights($field->id, $settings->fieldMatchWeight, $settings->partialFieldMatchWeight);
        $this->assertNull(FieldConfig::query()->where('fieldId', $field->id)->first());
    }

    public function testIndexableFields(): void
    {
        $handles = array_map(fn($field) => $field->handle, $this->plugin()->index->getIndexableFields());

        $this->assertContains('rsBody', $handles);
        $this->assertContains('rsBlocks', $handles);
    }

    public function testSaveSettingsKeepsOtherSettings(): void
    {
        $path = ProjectConfigService::PATH_PLUGINS . '.rawsearch.settings';
        $original = ProjectConfig::get($path) ?? [];

        try {
            $this->plugin()->saveSettings(['apiKey' => 'test-key-123']);
            $this->plugin()->saveSettings(['titleMatchWeight' => 123]);

            $stored = ProjectConfig::get($path);
            $this->assertSame('test-key-123', $stored['apiKey']);
            $this->assertSame(123, (int)$stored['titleMatchWeight']);
        } finally {
            ProjectConfig::set($path, $original);
        }
    }

    public function testBlacklistedWordList(): void
    {
        $this->setSettings(['blacklistedWords' => ' the, and ,,or ']);

        $this->assertSame(['the', 'and', 'or'], $this->plugin()->getSettings()->getBlacklistedWordList());
    }
}
