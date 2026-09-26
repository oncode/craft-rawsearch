<?php

namespace oncode\rawsearch\controllers;

use Craft;
use craft\web\Controller;
use oncode\rawsearch\RawSearch;
use yii\web\Response;

/**
 * Control panel pages for the weight and indexing settings.
 */
class SettingsController extends Controller
{
    /** Plugin settings that can be changed on the weight/indexing pages. */
    private const WEIGHT_SETTINGS = [
        'titleMatchWeight',
        'partialTitleMatchWeight',
        'fieldMatchWeight',
        'partialFieldMatchWeight',
        'elementTypeMatchWeight',
    ];
    private const INDEX_SETTINGS = ['blacklistedWords'];

    public function beforeAction($action): bool
    {
        $this->requirePermission(RawSearch::PERMISSION_EDIT_SETTINGS);

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $user = Craft::$app->getUser();

        if ($user->checkPermission(RawSearch::PERMISSION_EDIT_WEIGHT_SETTINGS)) {
            return $this->redirect('rawsearch/settings/weight/general');
        }

        return $this->redirect('rawsearch/settings/indexing/words');
    }

    // General
    // -------------------------------------------------------------------------

    public function actionGeneral(): Response
    {
        $this->requireAdmin(false);

        return $this->renderSettingsTemplate('rawsearch/settings/general');
    }

    public function actionSaveGeneral(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $values = array_intersect_key(
            (array)$this->request->getBodyParam('settings', []),
            array_flip(['name', 'statistic', 'apiKey'])
        );

        if (isset($values['statistic'])) {
            $values['statistic'] = (bool)$values['statistic'];
        }

        return $this->savePluginSettings($values, false);
    }

    // Weight
    // -------------------------------------------------------------------------

    public function actionWeightGeneral(): Response
    {
        $this->requirePermission(RawSearch::PERMISSION_EDIT_WEIGHT_SETTINGS);

        return $this->renderSettingsTemplate('rawsearch/settings/weight/general');
    }

    public function actionWeightElementTypes(): Response
    {
        $this->requirePermission(RawSearch::PERMISSION_EDIT_WEIGHT_SETTINGS);
        $configs = RawSearch::getInstance()->elementTypeConfigs;

        return $this->renderSettingsTemplate('rawsearch/settings/weight/element-types', [
            'elementTypes' => $this->elementTypeOptions(RawSearch::getInstance()->index->getIndexedElementTypes()),
            'matchWeights' => $configs->getAllMatchWeights(),
        ]);
    }

    public function actionSaveElementTypeWeights(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(RawSearch::PERMISSION_EDIT_WEIGHT_SETTINGS);
        $configs = RawSearch::getInstance()->elementTypeConfigs;

        foreach ((array)$this->request->getBodyParam('elementTypes', []) as $type => $weight) {
            if ($configs->resolveElementType($type) && $weight !== '') {
                $configs->saveMatchWeight($type, (int)$weight);
            }
        }

        return $this->asSuccess(Craft::t('rawsearch', 'Settings have been saved.'));
    }

    public function actionWeightFields(): Response
    {
        $this->requirePermission(RawSearch::PERMISSION_EDIT_WEIGHT_SETTINGS);
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

    public function actionSaveFieldWeights(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(RawSearch::PERMISSION_EDIT_WEIGHT_SETTINGS);
        $fieldConfigs = RawSearch::getInstance()->fieldConfigs;
        $settings = RawSearch::getInstance()->getSettings();

        foreach ((array)$this->request->getBodyParam('fields', []) as $fieldId => $weights) {
            if (!Craft::$app->getFields()->getFieldById((int)$fieldId)) {
                continue;
            }

            $fieldConfigs->saveMatchWeights(
                (int)$fieldId,
                ($weights['matchWeight'] ?? '') !== '' ? (int)$weights['matchWeight'] : $settings->fieldMatchWeight,
                ($weights['partialMatchWeight'] ?? '') !== '' ? (int)$weights['partialMatchWeight'] : $settings->partialFieldMatchWeight,
            );
        }

        return $this->asSuccess(Craft::t('rawsearch', 'Settings have been saved.'));
    }

    // Indexing
    // -------------------------------------------------------------------------

    public function actionIndexingWords(): Response
    {
        $this->requirePermission(RawSearch::PERMISSION_EDIT_INDEX_SETTINGS);

        return $this->renderSettingsTemplate('rawsearch/settings/indexing/words');
    }

    public function actionIndexingFieldTypes(): Response
    {
        $this->requirePermission(RawSearch::PERMISSION_EDIT_INDEX_SETTINGS);
        $fieldTypes = array_map(fn($class) => [
            'class' => $class,
            'name' => $class::displayName(),
        ], Craft::$app->getFields()->getAllFieldTypes());
        usort($fieldTypes, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return $this->renderSettingsTemplate('rawsearch/settings/indexing/field-types', [
            'fieldTypes' => $fieldTypes,
        ]);
    }

    public function actionSaveFieldTypes(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(RawSearch::PERMISSION_EDIT_INDEX_SETTINGS);

        $allTypes = Craft::$app->getFields()->getAllFieldTypes();
        $posted = (array)$this->request->getBodyParam('fieldTypes', []);
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

    public function actionIndexingFields(): Response
    {
        $this->requirePermission(RawSearch::PERMISSION_EDIT_INDEX_SETTINGS);

        return $this->renderSettingsTemplate('rawsearch/settings/indexing/fields', [
            'fields' => RawSearch::getInstance()->index->getIndexableFields(),
            'blacklistedFieldIds' => RawSearch::getInstance()->fieldConfigs->getBlacklistedFieldIds(),
        ]);
    }

    public function actionSaveFieldIndexes(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(RawSearch::PERMISSION_EDIT_INDEX_SETTINGS);
        $fieldConfigs = RawSearch::getInstance()->fieldConfigs;

        foreach ((array)$this->request->getBodyParam('fields', []) as $fieldId => $index) {
            if (Craft::$app->getFields()->getFieldById((int)$fieldId)) {
                $fieldConfigs->saveIndex((int)$fieldId, (bool)$index);
            }
        }

        return $this->asSuccess(Craft::t('rawsearch', 'Settings have been saved. Update the search index to apply the changes.'));
    }

    public function actionIndexingElementTypes(): Response
    {
        $this->requirePermission(RawSearch::PERMISSION_EDIT_INDEX_SETTINGS);

        return $this->renderSettingsTemplate('rawsearch/settings/indexing/element-types', [
            'elementTypes' => $this->elementTypeOptions(RawSearch::getInstance()->elementTypeConfigs->getAllElementTypes()),
            'blacklistedElementTypes' => RawSearch::getInstance()->elementTypeConfigs->getBlacklistedElementTypes(),
        ]);
    }

    public function actionSaveElementTypeIndexes(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(RawSearch::PERMISSION_EDIT_INDEX_SETTINGS);
        $plugin = RawSearch::getInstance();
        $configs = $plugin->elementTypeConfigs;
        $newlyIndexed = [];

        foreach ((array)$this->request->getBodyParam('elementTypes', []) as $type => $index) {
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

        return $this->asSuccess(Craft::t('rawsearch', 'Settings have been saved.'));
    }

    /**
     * Saves the general settings of the weight and indexing pages.
     */
    public function actionSaveSettings(): ?Response
    {
        $this->requirePostRequest();
        $user = Craft::$app->getUser();
        $allowed = [];

        if ($user->checkPermission(RawSearch::PERMISSION_EDIT_WEIGHT_SETTINGS)) {
            $allowed = array_merge($allowed, self::WEIGHT_SETTINGS);
        }

        if ($user->checkPermission(RawSearch::PERMISSION_EDIT_INDEX_SETTINGS)) {
            $allowed = array_merge($allowed, self::INDEX_SETTINGS);
        }

        $posted = array_intersect_key((array)$this->request->getBodyParam('settings', []), array_flip($allowed));

        return $this->savePluginSettings($posted, isset($posted['blacklistedWords']));
    }

    /**
     * Updates the search index of an element type or of all element types.
     */
    public function actionReindex(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(RawSearch::PERMISSION_EDIT_INDEX_SETTINGS);
        $index = RawSearch::getInstance()->index;
        $elementType = $this->request->getBodyParam('elementType');

        if ($elementType) {
            $class = RawSearch::getInstance()->elementTypeConfigs->resolveElementType($elementType);

            if (!$class) {
                return $this->asFailure(Craft::t('rawsearch', 'Invalid element type.'));
            }

            $index->queueElementType($class);
        } else {
            $index->queueAll();
        }

        return $this->asSuccess(Craft::t('rawsearch', 'Search index update started.'));
    }

    private function savePluginSettings(array $values, bool $affectsIndex): ?Response
    {
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            return $this->asFailure(Craft::t('rawsearch', 'Settings can’t be changed because admin changes are disallowed in this environment.'));
        }

        $plugin = RawSearch::getInstance();

        if (!$plugin->saveSettings($values)) {
            return $this->asFailure(Craft::t('rawsearch', 'Settings could not be saved.'));
        }

        return $this->asSuccess($affectsIndex
            ? Craft::t('rawsearch', 'Settings have been saved. Update the search index to apply the changes.')
            : Craft::t('rawsearch', 'Settings have been saved.'));
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
        return $this->renderTemplate($template, $variables + [
            'settings' => RawSearch::getInstance()->getSettings(),
            'allowAdminChanges' => Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }
}
