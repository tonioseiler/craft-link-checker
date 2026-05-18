<?php

namespace furbo\craftlinkchecker\console\controllers;

use craft\console\Controller;
use furbo\craftlinkchecker\CraftLinkChecker;
use yii\console\ExitCode;

class LinkCheckerController extends Controller
{
    public function actionRun(): int
    {
        error_reporting(8191);

        $service = CraftLinkChecker::getInstance()->linkCheckerService;

        $this->stdout("Results file : " . $service->getResultsFilePath() . "\n");
        $this->stdout("Starting…\n");

        $service->saveResults([
            'status' => 'running',
            'lastRun' => null,
            'pagesChecked' => 0,
            'linksFound' => 0,
            'linksChecked' => 0,
            'brokenCount' => 0,
            'results' => [],
        ]);

        try {
            $results = $service->runCheck(
                checkpointCallback: function(array $partial) use ($service) {
                    $service->saveResults($partial);
                    $done = $partial['pagesChecked'];
                    $total = $partial['pagesTotal'];
                    $broken = $partial['brokenCount'];
                    $pct = $total > 0 ? round($done / $total * 100) : 0;
                    $this->stdout("\r{$pct}% --- Entries: {$done}/{$total} --- Broken links: {$broken}  ");
                },
            );
        } catch (\Throwable $e) {
            $this->stdout("\nFatal error: " . $e->getMessage() . "\n");
            $results = $service->getResults();
            $results['status'] = 'error';
            $results['error'] = $e->getMessage();
            $service->saveResults($results);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $service->saveResults($results);

        $done = $results['pagesChecked'];
        $broken = $results['brokenCount'];
        $file = $service->getResultsFilePath();
        $this->stdout("100% --- Entries: {$done}/{$done} --- Broken links: {$broken}\n");
        $this->stdout(file_exists($file) ? "Saved to: {$file}\n" : "WARNING: could not write {$file}\n");

        return ExitCode::OK;
    }
}
