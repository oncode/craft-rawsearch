<?php

namespace oncode\rawsearch\controllers;

use CraftCms\Cms\Http\RespondsWithFlash;
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Http\Request;
use oncode\rawsearch\RawSearch;
use Symfony\Component\HttpFoundation\Response;

use function CraftCms\Cms\cp_redirect;
use function CraftCms\Cms\pageTemplate;
use function CraftCms\Cms\t;

/**
 * Control panel pages showing the stored search queries.
 * The permission is checked by the route middleware.
 */
class StatisticController
{
    use RespondsWithFlash;

    private const PER_PAGE = 50;

    public function index(): Response
    {
        return cp_redirect('rawsearch/statistic/queries');
    }

    public function queries(Request $request): Response
    {
        $site = $this->selectedSite($request);
        $page = max(1, (int)$request->input('page', 1));
        $query = RawSearch::getInstance()->queries->find($site?->id);
        $total = (clone $query)->count();
        $totalPages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = min($page, $totalPages);

        return response(pageTemplate('rawsearch/statistic/queries', [
            'selectedSite' => $site,
            'queries' => $query->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get()->map(fn($row) => (array)$row)->all(),
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages,
            'perPage' => self::PER_PAGE,
        ]));
    }

    public function top(Request $request): Response
    {
        $site = $this->selectedSite($request);
        $days = (int)$request->input('days', 0);
        $since = $days > 0 ? (new \DateTime())->modify("-$days days") : null;

        return response(pageTemplate('rawsearch/statistic/top', [
            'selectedSite' => $site,
            'days' => $days,
            'queries' => RawSearch::getInstance()->queries->getMostSearched($site?->id, 20, $since),
        ]));
    }

    public function clear(Request $request): Response
    {
        $site = $this->selectedSite($request);
        RawSearch::getInstance()->queries->deleteAll($site?->id);

        return $this->asSuccess(t('Search queries have been deleted.', category: 'rawsearch'));
    }

    private function selectedSite(Request $request): ?Site
    {
        $handle = $request->input('searchSite');

        return $handle ? Sites::getSiteByHandle($handle) : null;
    }
}
