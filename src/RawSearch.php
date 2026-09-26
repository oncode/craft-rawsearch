<?php

namespace oncode\rawsearch;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\ElementEvent;
use craft\events\PluginEvent;
use craft\events\EntryTypeEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\SectionEvent;
use craft\helpers\Queue;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\services\Elements;
use craft\services\Entries;
use craft\services\Plugins;
use craft\services\ProjectConfig;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use oncode\rawsearch\jobs\IndexElements;
use oncode\rawsearch\models\Settings;
use oncode\rawsearch\services\Autocomplete;
use oncode\rawsearch\services\ElementTypeConfigs;
use oncode\rawsearch\services\FieldConfigs;
use oncode\rawsearch\services\Index;
use oncode\rawsearch\services\Queries;
use oncode\rawsearch\services\Search;
use oncode\rawsearch\services\Sort;
use oncode\rawsearch\variables\RawSearchVariable;
use yii\base\Application;
use yii\base\Event;

/**
 * RawSearch: highly customizable text search with weighted results and result snippets.
 *
 * @property-read Search $search
 * @property-read Index $index
 * @property-read Sort $sort
 * @property-read Autocomplete $autocomplete
 * @property-read ElementTypeConfigs $elementTypeConfigs
 * @property-read FieldConfigs $fieldConfigs
 * @property-read Queries $queries
 * @method Settings getSettings()
 */
class RawSearch extends Plugin
{
    public const PERMISSION_ACCESS_STATISTIC = 'rawsearch-accessStatistic';
    public const PERMISSION_EDIT_SETTINGS = 'rawsearch-editSettings';
    public const PERMISSION_EDIT_INDEX_SETTINGS = 'rawsearch-editIndexSettings';
    public const PERMISSION_EDIT_WEIGHT_SETTINGS = 'rawsearch-editWeightSettings';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'search' => Search::class,
                'index' => Index::class,
                'sort' => Sort::class,
                'autocomplete' => Autocomplete::class,
                'elementTypeConfigs' => ElementTypeConfigs::class,
                'fieldConfigs' => FieldConfigs::class,
                'queries' => Queries::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerTwigVariable();
        $this->registerPermissions();
        $this->registerCpRoutes();

        // wait until Craft is fully initialized before touching elements
        Craft::$app->onInit(function() {
            $this->registerIndexEvents();
        });
    }

    public function getCpNavItem(): ?array
    {
        $user = Craft::$app->getUser();
        $item = parent::getCpNavItem();
        $item['label'] = $this->getSettings()->name;
        $item['subnav'] = [];

        if ($user->checkPermission(self::PERMISSION_ACCESS_STATISTIC)) {
            $item['subnav']['statistic'] = ['label' => Craft::t('rawsearch', 'Statistic'), 'url' => 'rawsearch/statistic'];
        }

        if ($user->checkPermission(self::PERMISSION_EDIT_SETTINGS)) {
            $item['subnav']['settings'] = ['label' => Craft::t('rawsearch', 'Settings'), 'url' => 'rawsearch/settings'];
        }

        if (empty($item['subnav'])) {
            return null;
        }

        $item['url'] = reset($item['subnav'])['url'];

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * The settings are edited on the plugin's own settings pages.
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('rawsearch/settings/general'));
    }

    /**
     * Saves some settings and keeps the others.
     * Plugins::savePluginSettings() replaces all stored settings with the given ones.
     */
    public function saveSettings(array $values): bool
    {
        $stored = Craft::$app->getProjectConfig()->get(ProjectConfig::PATH_PLUGINS . '.' . $this->handle . '.settings') ?? [];

        return Craft::$app->getPlugins()->savePluginSettings($this, array_merge($stored, $values));
    }

    protected function afterInstall(): void
    {
        // build the index for the existing content
        $this->index->queueAll();

        // the plugin's project config gets overwritten after afterInstall(), so settings are saved afterwards
        Event::on(Plugins::class, Plugins::EVENT_AFTER_INSTALL_PLUGIN, function(PluginEvent $event) {
            if ($event->plugin === $this && $this->getSettings()->apiKey === '') {
                $this->generateApiKey();
            }
        });
    }

    public function generateApiKey(): bool
    {
        return $this->saveSettings(['apiKey' => StringHelper::randomString(24)]);
    }

    private function registerTwigVariable(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('rawSearch', RawSearchVariable::class);
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => $this->getSettings()->name,
                'permissions' => [
                    self::PERMISSION_ACCESS_STATISTIC => [
                        'label' => Craft::t('rawsearch', 'Access statistic'),
                    ],
                    self::PERMISSION_EDIT_SETTINGS => [
                        'label' => Craft::t('rawsearch', 'Edit settings'),
                        'nested' => [
                            self::PERMISSION_EDIT_INDEX_SETTINGS => ['label' => Craft::t('rawsearch', 'Indexing')],
                            self::PERMISSION_EDIT_WEIGHT_SETTINGS => ['label' => Craft::t('rawsearch', 'Weight')],
                        ],
                    ],
                ],
            ];
        });
    }

    private function registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                'rawsearch' => 'rawsearch/statistic/index',
                'rawsearch/statistic' => 'rawsearch/statistic/index',
                'rawsearch/statistic/queries' => 'rawsearch/statistic/queries',
                'rawsearch/statistic/top' => 'rawsearch/statistic/top',
                'rawsearch/settings' => 'rawsearch/settings/index',
                'rawsearch/settings/general' => 'rawsearch/settings/general',
                'rawsearch/settings/weight' => 'rawsearch/settings/weight-general',
                'rawsearch/settings/weight/general' => 'rawsearch/settings/weight-general',
                'rawsearch/settings/weight/element-types' => 'rawsearch/settings/weight-element-types',
                'rawsearch/settings/weight/fields' => 'rawsearch/settings/weight-fields',
                'rawsearch/settings/indexing' => 'rawsearch/settings/indexing-words',
                'rawsearch/settings/indexing/words' => 'rawsearch/settings/indexing-words',
                'rawsearch/settings/indexing/field-types' => 'rawsearch/settings/indexing-field-types',
                'rawsearch/settings/indexing/fields' => 'rawsearch/settings/indexing-fields',
                'rawsearch/settings/indexing/element-types' => 'rawsearch/settings/indexing-element-types',
            ];
        });
    }

    private function registerIndexEvents(): void
    {
        $index = $this->index;

        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function(ElementEvent $event) use ($index) {
            // propagation to other sites is covered, the job indexes all sites
            if (!$event->element->propagating) {
                $index->queueElement($event->element);
            }
        });

        Event::on(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, function(ElementEvent $event) use ($index) {
            $index->queueElement($event->element);
        });

        Event::on(Elements::class, Elements::EVENT_AFTER_UPDATE_SLUG_AND_URI, function(ElementEvent $event) use ($index) {
            $index->queueElement($event->element);
        });

        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, function(ElementEvent $event) use ($index) {
            $element = $event->element;
            $index->removeByElementIds([$element->id]);

            // a deleted nested element changes the content of its owner
            if ($index->getIndexedElement($element) !== $element) {
                $index->queueElement($element);
            }
        });

        Event::on(Entries::class, Entries::EVENT_BEFORE_DELETE_SECTION, function(SectionEvent $event) use ($index) {
            $ids = Entry::find()->sectionId($event->section->id)->status(null)->site('*')->unique()->ids();
            $index->removeByElementIds($ids);
        });

        // field layout of an entry type changed, the indexed fields may be different
        Event::on(Entries::class, Entries::EVENT_AFTER_SAVE_ENTRY_TYPE, function(EntryTypeEvent $event) use ($index) {
            if ($event->isNew) {
                return;
            }

            $ids = Entry::find()->typeId($event->entryType->id)->status(null)->site('*')->unique()->ids();

            if (!empty($ids)) {
                Queue::push(new IndexElements(['elementType' => Entry::class, 'elementIds' => $ids]));
            }
        });

        // push the collected elements as one job per element type at the end of the request
        Craft::$app->on(Application::EVENT_AFTER_REQUEST, function() use ($index) {
            $index->pushQueuedElements();
        });
    }
}
