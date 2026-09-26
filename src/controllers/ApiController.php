<?php

namespace oncode\rawsearch\controllers;

use Craft;
use craft\web\Controller;
use oncode\rawsearch\RawSearch;
use yii\base\InvalidArgumentException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * JSON API for searching, autocompleting and reindexing.
 */
class ApiController extends Controller
{
    /** Actions that are protected by the API key (or a logged in user with the right permission). */
    private const KEY_ACTIONS = ['queries', 'reindex-elements', 'reindex-element-types'];

    protected array|bool|int $allowAnonymous = [
        'search',
        'autocomplete',
        'queries',
        'reindex-elements',
        'reindex-element-types',
    ];

    public function beforeAction($action): bool
    {
        // requests authenticated with the api key come from other systems and have no CSRF token
        if (in_array($action->id, self::KEY_ACTIONS, true) && $this->request->getParam('key') !== null) {
            $this->enableCsrfValidation = false;
        }

        return parent::beforeAction($action);
    }

    /**
     * Returns search results as JSON (or rendered HTML with `html=1`).
     */
    public function actionSearch(): Response
    {
        $params = $this->searchParams();

        foreach (['or' => 'bool', 'mode' => 'int', 'page' => 'int', 'resultsPerPage' => 'int', 'weightedSort' => 'bool'] as $name => $type) {
            $value = $this->request->getParam($name);

            if ($value !== null && $value !== '') {
                $params[$name] = $type === 'int' ? (int)$value : (bool)$value;
            }
        }

        $extract = $this->request->getParam('extract');

        if (is_array($extract)) {
            $params['extract'] = $extract;
        } elseif ($extract !== null && $extract !== '') {
            $params['extract'] = ['enabled' => (bool)$extract];
        }

        try {
            $search = RawSearch::getInstance()->search->search($params);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }

        if ($this->request->getParam('html')) {
            $result = RawSearch::getInstance()->search->renderResults($search, $params['query']);
        } else {
            // elements are not serialized, they would expose all their data
            $result = array_map(function($row) {
                unset($row['element']);
                return $row;
            }, $search['results']);
        }

        $pagination = $search['pagination'];

        return $this->asJson([
            'error' => false,
            'total' => $search['total'],
            'pagination' => [
                'first' => $pagination->first,
                'last' => $pagination->last,
                'total' => $pagination->total,
                'currentPage' => $pagination->currentPage,
                'totalPages' => $pagination->totalPages,
            ],
            'result' => $result,
        ]);
    }

    /**
     * Returns the words starting with the given query.
     */
    public function actionAutocomplete(): Response
    {
        $params = $this->searchParams();
        $limit = $this->request->getParam('limit');

        if ($limit !== null && $limit !== '') {
            $params['limit'] = (int)$limit;
        }

        try {
            $words = RawSearch::getInstance()->autocomplete->search($params);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }

        return $this->asJson([
            'error' => false,
            'result' => $words,
        ]);
    }

    /**
     * Returns the most searched queries.
     */
    public function actionQueries(): Response
    {
        $this->requireApiKey(RawSearch::PERMISSION_ACCESS_STATISTIC);

        $site = $this->request->getParam('site');
        $siteId = $site ? RawSearch::getInstance()->search->resolveSite($site)->id : null;
        $limit = (int)$this->request->getParam('limit', 10);

        return $this->asJson([
            'error' => false,
            'result' => RawSearch::getInstance()->queries->getMostSearched($siteId, max(1, min($limit, 1000))),
        ]);
    }

    /**
     * Reindexes elements by id (`elementIds`, comma separated).
     */
    public function actionReindexElements(): Response
    {
        $this->requireApiKey(RawSearch::PERMISSION_EDIT_INDEX_SETTINGS);

        $ids = $this->request->getParam('elementIds', '');
        $ids = array_filter(array_map('intval', is_array($ids) ? $ids : explode(',', (string)$ids)));
        $index = RawSearch::getInstance()->index;

        $elements = Craft::$app->getElements();

        foreach ($ids as $id) {
            $element = $elements->getElementById($id, null, '*');

            if ($element) {
                $index->queueElement($element);
            }
        }

        $index->pushQueuedElements();

        return $this->taskResponse();
    }

    /**
     * Reindexes element types (`elementTypes`, comma separated, or `all=1`).
     */
    public function actionReindexElementTypes(): Response
    {
        $this->requireApiKey(RawSearch::PERMISSION_EDIT_INDEX_SETTINGS);

        $index = RawSearch::getInstance()->index;

        if ($this->request->getParam('all')) {
            $index->queueAll();
            return $this->taskResponse();
        }

        try {
            $types = RawSearch::getInstance()->search->resolveElementTypes($this->request->getParam('elementTypes')) ?? [];
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e);
        }

        foreach ($types as $type) {
            $index->queueElementType($type);
        }

        return $this->taskResponse();
    }

    private function searchParams(): array
    {
        $params = [
            'query' => (string)$this->request->getParam('query', ''),
            'site' => $this->request->getParam('site'),
        ];

        $elementTypes = $this->request->getParam('elementTypes');

        if ($elementTypes) {
            $params['elementTypes'] = is_array($elementTypes) ? $elementTypes : explode(',', (string)$elementTypes);
        }

        return $params;
    }

    private function requireApiKey(string $permission): void
    {
        $apiKey = RawSearch::getInstance()->getSettings()->apiKey;
        $key = (string)$this->request->getParam('key', '');

        if ($apiKey !== '' && $key !== '' && hash_equals($apiKey, $key)) {
            return;
        }

        $user = Craft::$app->getUser();

        if (!$user->getIsGuest() && $user->checkPermission($permission)) {
            return;
        }

        throw new ForbiddenHttpException('Invalid API key');
    }

    private function taskResponse(): Response
    {
        $message = Craft::t('rawsearch', 'Search index update started.');

        if ($this->request->getBodyParam('redirect') !== null) {
            $this->setSuccessFlash($message);
            return $this->redirectToPostedUrl();
        }

        return $this->asJson([
            'error' => false,
            'message' => $message,
        ]);
    }

    private function errorResponse(\Throwable $e): Response
    {
        $isUserError = $e instanceof InvalidArgumentException;

        if (!$isUserError) {
            Craft::error($e, 'rawsearch');
        }

        $message = $isUserError || Craft::$app->getConfig()->getGeneral()->devMode
            ? $e->getMessage()
            : 'An error occurred while searching.';

        $this->response->setStatusCode($isUserError ? 400 : 500);

        return $this->asJson([
            'error' => true,
            'result' => null,
            'message' => $message,
        ]);
    }
}
