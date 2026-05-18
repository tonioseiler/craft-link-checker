<?php

namespace furbo\craftlinkchecker\jobs;

use craft\i18n\Translation;
use craft\queue\BaseJob;
use furbo\craftlinkchecker\CraftLinkChecker;

class LinkCheckerJob extends BaseJob
{
    public function execute($queue): void
    {
        $service = CraftLinkChecker::getInstance()->linkCheckerService;

        $results = $service->runCheck(
            progressCallback: function(int $processed, int $total) use ($queue) {
                $this->setProgress($queue, $processed / max($total, 1), Translation::prep('craft-link-checker', 'Checking entry {processed} of {total}…', ['processed' => $processed, 'total' => $total]));
            },
            checkpointCallback: function(array $partial) use ($service) {
                $service->saveResults($partial);
            },
        );

        $service->saveResults($results);
    }

    protected function defaultDescription(): ?string
    {
        return Translation::prep('craft-link-checker', 'Checking links');
    }
}
