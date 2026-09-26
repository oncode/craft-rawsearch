<?php

namespace oncode\rawsearch\controllers;

use Craft;
use craft\web\Controller;
use oncode\rawsearch\RawSearch;
use yii\web\Response;

/**
 * Control panel pages showing the stored search queries.
 */
class StatisticController extends Controller
{
    private const PER_PAGE = 50;

    public function beforeAction($action): bool
    {
        $this->requirePermission(RawSearch::PERMISSION_ACCESS_STATISTIC);

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        return $this->redirect('rawsearch/statistic/queries');
    }

    public function actionQueries(): Response
    {
        $site = $this->selectedSite();
        $page = max(1, (int)$this->request->getParam('page', 1));
        $query = RawSearch::getInstance()->queries->find($site?->id);
        $total = (int)$query->count();
        $totalPages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = min($page, $totalPages);

        return $this->renderTemplate('rawsearch/statistic/queries', [
            'selectedSite' => $site,
            'queries' => $query->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->all(),
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages,
            'perPage' => self::PER_PAGE,
        ]);
    }

    public function actionTop(): Response
    {
        $site = $this->selectedSite();
        $days = (int)$this->request->getParam('days', 0);
        $since = $days > 0 ? (new \DateTime())->modify("-$days days") : null;

        return $this->renderTemplate('rawsearch/statistic/top', [
            'selectedSite' => $site,
            'days' => $days,
            'queries' => RawSearch::getInstance()->queries->getMostSearched($site?->id, 20, $since),
        ]);
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();

        $site = $this->selectedSite();
        RawSearch::getInstance()->queries->deleteAll($site?->id);

        return $this->asSuccess(Craft::t('rawsearch', 'Search queries have been deleted.'));
    }

    private function selectedSite(): ?\craft\models\Site
    {
        $handle = $this->request->getParam('searchSite');

        return $handle ? Craft::$app->getSites()->getSiteByHandle($handle) : null;
    }
}
