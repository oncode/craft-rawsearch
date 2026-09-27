<?php

namespace oncode\rawsearch\controllers;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Http\RespondsWithFlash;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Flash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use oncode\rawsearch\RawSearch;
use Symfony\Component\HttpFoundation\Response;

use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * JSON API for searching, autocompleting and reindexing.
 *
 * `queries` and `reindex-*` are protected by the API key (or a logged in user with the right permission).
 * Their routes skip Laravel's CSRF check, requests with the key come from other systems and have no token.
 */
class ApiController
{
    use RespondsWithFlash;

    /**
     * Returns search results as JSON (or rendered HTML with `html=1`).
     */
    public function search(Request $request): JsonResponse
    {
        $params = $this->searchParams($request);

        foreach (['or' => 'bool', 'mode' => 'int', 'page' => 'int', 'resultsPerPage' => 'int', 'weightedSort' => 'bool'] as $name => $type) {
            $value = $request->input($name);

            if ($value !== null && $value !== '') {
                $params[$name] = $type === 'int' ? (int)$value : (bool)$value;
            }
        }

        $extract = $request->input('extract');

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

        if ($request->input('html')) {
            $result = RawSearch::getInstance()->search->renderResults($search, $params['query']);
        } else {
            // elements are not serialized, they would expose all their data
            $result = array_map(function($row) {
                unset($row['element']);
                return $row;
            }, $search['results']);
        }

        $pagination = $search['pagination'];

        return new JsonResponse([
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
    public function autocomplete(Request $request): JsonResponse
    {
        $params = $this->searchParams($request);
        $limit = $request->input('limit');

        if ($limit !== null && $limit !== '') {
            $params['limit'] = (int)$limit;
        }

        try {
            $words = RawSearch::getInstance()->autocomplete->search($params);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }

        return new JsonResponse([
            'error' => false,
            'result' => $words,
        ]);
    }

    /**
     * Returns the most searched queries.
     */
    public function queries(Request $request): JsonResponse
    {
        $this->requireApiKey($request, RawSearch::PERMISSION_ACCESS_STATISTIC);

        $site = $request->input('site');

        try {
            $siteId = $site ? RawSearch::getInstance()->search->resolveSite($site)->id : null;
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e);
        }

        $limit = (int)$request->input('limit', 10);

        return new JsonResponse([
            'error' => false,
            'result' => RawSearch::getInstance()->queries->getMostSearched($siteId, max(1, min($limit, 1000))),
        ]);
    }

    /**
     * Reindexes elements by id (`elementIds`, comma separated).
     */
    public function reindexElements(Request $request): Response
    {
        $this->requireApiKey($request, RawSearch::PERMISSION_EDIT_INDEX_SETTINGS);

        $ids = $request->input('elementIds', '');
        $ids = array_filter(array_map('intval', is_array($ids) ? $ids : explode(',', (string)$ids)));
        $index = RawSearch::getInstance()->index;

        foreach ($ids as $id) {
            $element = Elements::getElementById($id, null, '*');

            if ($element) {
                $index->queueElement($element);
            }
        }

        $index->pushQueuedElements();

        return $this->taskResponse($request);
    }

    /**
     * Reindexes element types (`elementTypes`, comma separated, or `all=1`).
     */
    public function reindexElementTypes(Request $request): Response
    {
        $this->requireApiKey($request, RawSearch::PERMISSION_EDIT_INDEX_SETTINGS);

        $index = RawSearch::getInstance()->index;

        if ($request->input('all')) {
            $index->queueAll();
            return $this->taskResponse($request);
        }

        try {
            $types = RawSearch::getInstance()->search->resolveElementTypes($request->input('elementTypes')) ?? [];
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e);
        }

        foreach ($types as $type) {
            $index->queueElementType($type);
        }

        return $this->taskResponse($request);
    }

    private function searchParams(Request $request): array
    {
        $params = [
            'query' => (string)$request->input('query', ''),
            'site' => $request->input('site'),
        ];

        $elementTypes = $request->input('elementTypes');

        if ($elementTypes) {
            $params['elementTypes'] = is_array($elementTypes) ? $elementTypes : explode(',', (string)$elementTypes);
        }

        return $params;
    }

    private function requireApiKey(Request $request, string $permission): void
    {
        $apiKey = RawSearch::getInstance()->getSettings()->apiKey;
        $key = (string)$request->input('key', '');

        if ($apiKey !== '' && $key !== '' && hash_equals($apiKey, $key)) {
            return;
        }

        abort_if(!currentUser() || !Gate::check($permission), 403, 'Invalid API key');

        // the route skips the CSRF check for requests with the key, session requests still need a token
        if (!$request->isMethodSafe()) {
            $token = (string)($request->input('_token') ?? $request->header('X-CSRF-TOKEN'));
            abort_unless($request->hasSession() && hash_equals((string)$request->session()->token(), $token), 419);
        }
    }

    private function taskResponse(Request $request): Response
    {
        $message = t('Search index update started.', category: 'rawsearch');

        if ($request->input('redirect') !== null) {
            Flash::success($message);
            return $this->redirectToPostedUrl();
        }

        return new JsonResponse([
            'error' => false,
            'message' => $message,
        ]);
    }

    private function errorResponse(\Throwable $e): JsonResponse
    {
        $isUserError = $e instanceof InvalidArgumentException;

        if (!$isUserError) {
            Log::error($e);
        }

        $message = $isUserError || Cms::config()->devMode
            ? $e->getMessage()
            : 'An error occurred while searching.';

        return new JsonResponse([
            'error' => true,
            'result' => null,
            'message' => $message,
        ], $isUserError ? 400 : 500);
    }
}
