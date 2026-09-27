<?php

namespace oncode\rawsearch\controllers;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Http\RespondsWithFlash;
use CraftCms\Cms\Support\Facades\Fields;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use oncode\rawsearch\RawSearch;
use Symfony\Component\HttpFoundation\Response;

use function CraftCms\Cms\cp_redirect;
use function CraftCms\Cms\pageTemplate;
use function CraftCms\Cms\t;

/**
 * Control panel pages for the weight and indexing settings.
 * Permissions, admin and POST requirements are checked by the route middleware.
 */
class SettingsController
{
    use RespondsWithFlash;

    /** Plugin settings that can be changed on the weight/indexing pages. */
    private const WEIGHT_SETTINGS = [
        'titleMatchWeight',
        'partialTitleMatchWeight',
        'fieldMatchWeight',
        'partialFieldMatchWeight',
        'elementTypeMatchWeight',
    ];
    private const INDEX_SETTINGS = ['blacklistedWords'];

    public function index(): Response
    {
        if (Gate::check(RawSearch::PERMISSION_EDIT_WEIGHT_SETTINGS)) {
            return cp_redirect('rawsearch/settings/weight/general');
        }

        return cp_redirect('rawsearch/settings/indexing/words');
    }

    // General
    // -------------------------------------------------------------------------

    public function general(): Response
    {
        return $this->renderSettingsTemplate('rawsearch/settings/general');
    }

    public function saveGeneral(Request $request): Response
    {
        $values = array_intersect_key(
            (array)$request->post('settings', []),
            array_flip(['name', 'statistic', 'apiKey'])
        );

        if (isset($values['statistic'])) {
            $values['statistic'] = (bool)$values['statistic'];
        }

        return $this->savePluginSettings($values, false);
    }

    // Weight
    // -------------------------------------------------------------------------

    public function weightGeneral(): Response
    {
        return $this->renderSettingsTemplate('rawsearch/settings/weight/general');
    }

    public function weightElementTypes(): Response
    {
        $configs = RawSearch::getInstance()->elementTypeConfigs;

        return $this->renderSettingsTemplate('rawsearch/settings/weight/element-types', [
            'elementTypes' => $this->elementTypeOptions(RawSearch::getInstance()->index->getIndexedElementTypes()),
            'matchWeights' => $configs->getAllMatchWeights(),
        ]);
    }

    public function saveElementTypeWeights(Request $request): Response
    {
        $configs = RawSearch::getInstance()->elementTypeConfigs;

        foreach ((array)$request->post('elementTypes', []) as $type => $weight) {
            if ($configs->resolveElementType($type) && $weight !== '') {
                $configs->saveMatchWeight($type, (int)$weight);
            }
        }

        return $this->asSuccess(t('Settings have been saved.', category: 'rawsearch'));
    }

    public function weightFields(): Response
    {
        $fieldConfigs = RawSearch::getInstance()->fieldConfigs;
        $fields = array_filter(
            RawSearch::getInstance()->index->getIndexableFields(),
            fn($field) => $fieldConfigs->isIndexed($field->id) && !RawSearch::getInstance()->index->isContainerField($field)
        );

        return $this->renderSettingsTemplate('rawsearch/settings/weight/fields', [
            'fields' => $fields,
            'matchWeights' => $fieldConfigs->getAllMatchWeights(),
        ]);
    }

    public function saveFieldWeights(Request $request): Response
    {
        $fieldConfigs = RawSearch::getInstance()->fieldConfigs;
        $settings = RawSearch::getInstance()->getSettings();

        foreach ((array)$request->post('fields', []) as $fieldId => $weights) {
            if (!Fields::getFieldById((int)$fieldId)) {
                continue;
            }

            $fieldConfigs->saveMatchWeights(
                (int)$fieldId,
                ($weights['matchWeight'] ?? '') !== '' ? (int)$weights['matchWeight'] : $settings->fieldMatchWeight,
                ($weights['partialMatchWeight'] ?? '') !== '' ? (int)$weights['partialMatchWeight'] : $settings->partialFieldMatchWeight,
            );
        }

        return $this->asSuccess(t('Settings have been saved.', category: 'rawsearch'));
    }

    // Indexing
    // -------------------------------------------------------------------------

    public function indexingWords(): Response
    {
        return $this->renderSettingsTemplate('rawsearch/settings/indexing/words');
    }

    public function indexingFieldTypes(): Response
    {
        $fieldTypes = array_map(fn($class) => [
            'class' => $class,
            'name' => $class::displayName(),
        ], Fields::getAllFieldTypes()->all());
        usort($fieldTypes, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return $this->renderSettingsTemplate('rawsearch/settings/indexing/field-types', [
            'fieldTypes' => $fieldTypes,
        ]);
    }

    public function saveFieldTypes(Request $request): Response
    {
        $allTypes = Fields::getAllFieldTypes()->all();
        $posted = (array)$request->post('fieldTypes', []);
        $settings = RawSearch::getInstance()->getSettings();

        // keep whitelisted types that aren't installed at the moment (e.g. a disabled plugin)
        $whitelisted = array_values(array_diff($settings->whitelistedFieldTypes, $allTypes));

        foreach ($allTypes as $type) {
            if (!empty($posted[$type])) {
                $whitelisted[] = $type;
            }
        }

        return $this->savePluginSettings(['whitelistedFieldTypes' => $whitelisted], true);
    }

    public function indexingFields(): Response
    {
        return $this->renderSettingsTemplate('rawsearch/settings/indexing/fields', [
            'fields' => RawSearch::getInstance()->index->getIndexableFields(),
            'blacklistedFieldIds' => RawSearch::getInstance()->fieldConfigs->getBlacklistedFieldIds(),
        ]);
    }

    public function saveFieldIndexes(Request $request): Response
    {
        $fieldConfigs = RawSearch::getInstance()->fieldConfigs;

        foreach ((array)$request->post('fields', []) as $fieldId => $index) {
            if (Fields::getFieldById((int)$fieldId)) {
                $fieldConfigs->saveIndex((int)$fieldId, (bool)$index);
            }
        }

        return $this->asSuccess(t('Settings have been saved. Update the search index to apply the changes.', category: 'rawsearch'));
    }

    public function indexingElementTypes(): Response
    {
        return $this->renderSettingsTemplate('rawsearch/settings/indexing/element-types', [
            'elementTypes' => $this->elementTypeOptions(RawSearch::getInstance()->elementTypeConfigs->getAllElementTypes()),
            'blacklistedElementTypes' => RawSearch::getInstance()->elementTypeConfigs->getBlacklistedElementTypes(),
        ]);
    }

    public function saveElementTypeIndexes(Request $request): Response
    {
        $plugin = RawSearch::getInstance();
        $configs = $plugin->elementTypeConfigs;
        $newlyIndexed = [];

        foreach ((array)$request->post('elementTypes', []) as $type => $index) {
            if (!$configs->resolveElementType($type)) {
                continue;
            }

            if ($index && !$configs->isIndexed($type)) {
                $newlyIndexed[] = $type;
            }

            $configs->saveIndex($type, (bool)$index);
        }

        // apply the changes to the index right away
        $plugin->index->removeNotIndexedElementTypes();

        foreach ($newlyIndexed as $type) {
            $plugin->index->queueElementType($type);
        }

        return $this->asSuccess(t('Settings have been saved.', category: 'rawsearch'));
    }

    /**
     * Saves the general settings of the weight and indexing pages.
     */
    public function saveSettings(Request $request): Response
    {
        $allowed = [];

        if (Gate::check(RawSearch::PERMISSION_EDIT_WEIGHT_SETTINGS)) {
            $allowed = array_merge($allowed, self::WEIGHT_SETTINGS);
        }

        if (Gate::check(RawSearch::PERMISSION_EDIT_INDEX_SETTINGS)) {
            $allowed = array_merge($allowed, self::INDEX_SETTINGS);
        }

        $posted = array_intersect_key((array)$request->post('settings', []), array_flip($allowed));

        return $this->savePluginSettings($posted, isset($posted['blacklistedWords']));
    }

    /**
     * Updates the search index of an element type or of all element types.
     */
    public function reindex(Request $request): Response
    {
        $index = RawSearch::getInstance()->index;
        $elementType = $request->post('elementType');

        if ($elementType) {
            $class = RawSearch::getInstance()->elementTypeConfigs->resolveElementType($elementType);

            if (!$class) {
                return $this->asFailure(t('Invalid element type.', category: 'rawsearch'));
            }

            $index->queueElementType($class);
        } else {
            $index->queueAll();
        }

        return $this->asSuccess(t('Search index update started.', category: 'rawsearch'));
    }

    private function savePluginSettings(array $values, bool $affectsIndex): Response
    {
        if (!Cms::config()->allowAdminChanges) {
            return $this->asFailure(t('Settings can’t be changed because admin changes are disallowed in this environment.', category: 'rawsearch'));
        }

        $plugin = RawSearch::getInstance();

        if (!$plugin->saveSettings($values)) {
            return $this->asFailure(t('Settings could not be saved.', category: 'rawsearch'));
        }

        return $this->asSuccess($affectsIndex
            ? t('Settings have been saved. Update the search index to apply the changes.', category: 'rawsearch')
            : t('Settings have been saved.', category: 'rawsearch'));
    }

    /**
     * @param string[] $elementTypes
     */
    private function elementTypeOptions(array $elementTypes): array
    {
        $options = array_map(fn($class) => [
            'class' => $class,
            'name' => $class::pluralDisplayName(),
        ], $elementTypes);
        usort($options, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return $options;
    }

    private function renderSettingsTemplate(string $template, array $variables = []): Response
    {
        return response(pageTemplate($template, $variables + [
            'settings' => RawSearch::getInstance()->getSettings(),
            'allowAdminChanges' => Cms::config()->allowAdminChanges,
        ]));
    }
}
