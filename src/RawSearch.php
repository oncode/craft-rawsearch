<?php

namespace oncode\rawsearch;

use CraftCms\Cms\Cp\Data\NavItem;
use CraftCms\Cms\Element\Events\ElementDeleted;
use CraftCms\Cms\Element\Events\ElementRestored;
use CraftCms\Cms\Element\Events\ElementSaved;
use CraftCms\Cms\Element\Events\ElementSlugAndUriUpdated;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Entry\Events\EntryTypeSaved;
use CraftCms\Cms\Plugin\Events\PluginInstalled;
use CraftCms\Cms\Plugin\Plugin;
use CraftCms\Cms\ProjectConfig\ProjectConfig as ProjectConfigService;
use CraftCms\Cms\ProjectConfig\ProjectConfigHelper;
use CraftCms\Cms\Section\Events\SectionDeleting;
use CraftCms\Cms\Support\Facades\Plugins;
use CraftCms\Cms\Support\Facades\ProjectConfig;
use CraftCms\Cms\Support\Str;
use CraftCms\Cms\Twig\Variables\CraftVariable;
use CraftCms\Cms\User\Data\Permission;
use CraftCms\Cms\User\Data\PermissionGroup;
use CraftCms\Cms\User\UserPermissions;
use CraftCms\Cms\Validation\Contracts\Validatable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use oncode\rawsearch\console\ApiKeyGenerateCommand;
use oncode\rawsearch\console\IndexAllCommand;
use oncode\rawsearch\console\IndexElementsCommand;
use oncode\rawsearch\console\IndexElementTypesCommand;
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

use function CraftCms\Cms\cp_redirect;
use function CraftCms\Cms\t;

/**
 * RawSearch: highly customizable text search with weighted results and result snippets.
 *
 * @method Settings getSettings()
 */
class RawSearch extends Plugin
{
    public const PERMISSION_ACCESS_STATISTIC = 'rawsearch-accessStatistic';
    public const PERMISSION_EDIT_SETTINGS = 'rawsearch-editSettings';
    public const PERMISSION_EDIT_INDEX_SETTINGS = 'rawsearch-editIndexSettings';
    public const PERMISSION_EDIT_WEIGHT_SETTINGS = 'rawsearch-editWeightSettings';

    /** The services, bound as singletons in the container. */
    public const SERVICES = [
        Search::class,
        Index::class,
        Sort::class,
        Autocomplete::class,
        ElementTypeConfigs::class,
        FieldConfigs::class,
        Queries::class,
    ];

    public string $schemaVersion = '2.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    protected array $commands = [
        IndexAllCommand::class,
        IndexElementTypesCommand::class,
        IndexElementsCommand::class,
        ApiKeyGenerateCommand::class,
    ];

    // shortcuts for the services, e.g. RawSearch::getInstance()->search
    public Search $search { get => $this->app->make(Search::class); }
    public Index $index { get => $this->app->make(Index::class); }
    public Sort $sort { get => $this->app->make(Sort::class); }
    public Autocomplete $autocomplete { get => $this->app->make(Autocomplete::class); }
    public ElementTypeConfigs $elementTypeConfigs { get => $this->app->make(ElementTypeConfigs::class); }
    public FieldConfigs $fieldConfigs { get => $this->app->make(FieldConfigs::class); }
    public Queries $queries { get => $this->app->make(Queries::class); }

    public function register(): void
    {
        foreach (self::SERVICES as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(): void
    {
        CraftVariable::macro('rawSearch', fn() => new RawSearchVariable());

        $this->registerPermissions();
        $this->registerIndexEvents();
    }

    public function getCpNavItem(): NavItem|array|null
    {
        $subnav = [];

        if (Gate::check(self::PERMISSION_ACCESS_STATISTIC)) {
            $subnav['statistic'] = ['label' => t('Statistic', category: 'rawsearch'), 'url' => 'rawsearch/statistic'];
        }

        if (Gate::check(self::PERMISSION_EDIT_SETTINGS)) {
            $subnav['settings'] = ['label' => t('Settings', category: 'rawsearch'), 'url' => 'rawsearch/settings'];
        }

        if (empty($subnav)) {
            return null;
        }

        $item = parent::getCpNavItem();

        return $item
            ->label($this->getSettings()->name)
            ->url(reset($subnav)['url'])
            ->subnav(array_map(fn($item) => new NavItem($item), $subnav));
    }

    protected function createSettingsModel(): ?Validatable
    {
        return new Settings();
    }

    /**
     * The settings are edited on the plugin's own settings pages.
     */
    public function getSettingsResponse(): mixed
    {
        return cp_redirect('rawsearch/settings/general');
    }

    /**
     * Saves some settings and keeps the others.
     * Plugins::savePluginSettings() writes all settings of the model, including the ones from the config file.
     */
    public function saveSettings(array $values): bool
    {
        $stored = ProjectConfig::get(ProjectConfigService::PATH_PLUGINS . '.' . $this->handle . '.settings') ?? [];
        $stored = ProjectConfigHelper::unpackAssociativeArrays($stored);

        return Plugins::savePluginSettings($this, array_merge($stored, $values));
    }

    protected function afterInstall(): void
    {
        // build the index for the existing content
        $this->index->queueAll();

        // The plugin isn't booted while it gets installed, so the listener is registered here.
        // Its project config gets overwritten after afterInstall(), so the settings are saved afterwards.
        Event::listen(PluginInstalled::class, function(PluginInstalled $event) {
            if ($event->plugin === $this && $this->getSettings()->apiKey === '') {
                $this->generateApiKey();
            }
        });
    }

    public function generateApiKey(): bool
    {
        return $this->saveSettings(['apiKey' => Str::random(24)]);
    }

    /**
     * @return Permission[]
     */
    protected function getPermissions(): array
    {
        return [
            new Permission(self::PERMISSION_ACCESS_STATISTIC, t('Access statistic', category: 'rawsearch')),
            new Permission(self::PERMISSION_EDIT_SETTINGS, t('Edit settings', category: 'rawsearch'), nested: collect([
                new Permission(self::PERMISSION_EDIT_INDEX_SETTINGS, t('Indexing', category: 'rawsearch')),
                new Permission(self::PERMISSION_EDIT_WEIGHT_SETTINGS, t('Weight', category: 'rawsearch')),
            ])),
        ];
    }

    /**
     * Replaces the permission group Craft registers, its heading is the plugin name from the settings.
     */
    private function registerPermissions(): void
    {
        $handle = "plugin:$this->handle";
        $permissions = $this->app->make(UserPermissions::class);
        $permissions->removePermissionGroups($handle);

        $permissions->registerPermissionGroup($handle, fn() => new PermissionGroup(
            handle: $handle,
            heading: $this->getSettings()->name,
            permissions: collect($this->getPermissions()),
        ));
    }

    private function registerIndexEvents(): void
    {
        Event::listen(ElementSaved::class, function(ElementSaved $event) {
            // propagation to other sites is covered, the job indexes all sites
            if (!$event->element->propagating) {
                $this->index->queueElement($event->element);
            }
        });

        Event::listen(ElementRestored::class, function(ElementRestored $event) {
            $this->index->queueElement($event->element);
        });

        Event::listen(ElementSlugAndUriUpdated::class, function(ElementSlugAndUriUpdated $event) {
            $this->index->queueElement($event->element);
        });

        Event::listen(ElementDeleted::class, function(ElementDeleted $event) {
            $element = $event->element;
            $this->index->removeByElementIds([$element->id]);

            // a deleted nested element changes the content of its owner
            if ($this->index->getIndexedElement($element) !== $element) {
                $this->index->queueElement($element);
            }
        });

        Event::listen(SectionDeleting::class, function(SectionDeleting $event) {
            $ids = Entry::find()->sectionId($event->section->id)->status(null)->site('*')->ids();
            $this->index->removeByElementIds(array_unique($ids));
        });

        // field layout of an entry type changed, the indexed fields may be different
        Event::listen(EntryTypeSaved::class, function(EntryTypeSaved $event) {
            if ($event->isNew) {
                return;
            }

            // unique() instead of array_unique() would find one element only (Craft 6.0.0-alpha.18)
            $ids = array_values(array_unique(Entry::find()->typeId($event->entryType->id)->status(null)->site('*')->ids()));

            if (!empty($ids)) {
                dispatch(new IndexElements(Entry::class, $ids));
            }
        });
    }
}
