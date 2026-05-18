<?php

namespace furbo\craftlinkchecker\controllers;

use Craft;
use craft\web\Controller;
use furbo\craftlinkchecker\CraftLinkChecker;
use furbo\craftlinkchecker\jobs\LinkCheckerJob;
use yii\web\Response;

class LinkCheckerController extends Controller
{
    public function actionRun(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessCp');
        $this->requirePostRequest();

        CraftLinkChecker::getInstance()->linkCheckerService->saveResults([
            'status' => 'running',
            'lastRun' => null,
            'lastCheckpoint' => date('c'),
            'pagesChecked' => 0,
            'linksFound' => 0,
            'linksChecked' => 0,
            'brokenCount' => 0,
            'results' => [],
        ]);

        Craft::$app->getQueue()->push(new LinkCheckerJob());

        return $this->asJson(['success' => true]);
    }

    public function actionStop(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessCp');
        $this->requirePostRequest();

        CraftLinkChecker::getInstance()->linkCheckerService->requestStop();

        return $this->asJson(['success' => true]);
    }

    public function actionResults(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessCp');

        return $this->asJson(CraftLinkChecker::getInstance()->linkCheckerService->getResults());
    }
}
