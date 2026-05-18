<?php

namespace furbo\craftlinkchecker\utilities;

use Craft;
use craft\base\Utility;
use furbo\craftlinkchecker\CraftLinkChecker;

class LinkCheckerUtility extends Utility
{
    public static function displayName(): string
    {
        return 'Link Checker';
    }

    public static function id(): string
    {
        return 'craft-link-checker';
    }

    public static function icon(): ?string
    {
        return dirname(__DIR__) . '/assets/linkChecker-mask.svg';
    }

    public static function contentHtml(): string
    {
        $results = CraftLinkChecker::getInstance()->linkCheckerService->getResults();

        return Craft::$app->getView()->renderTemplate(
            'craft-link-checker/_cp/index',
            ['initialData' => $results]
        );
    }
}
