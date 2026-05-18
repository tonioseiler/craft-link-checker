<?php

namespace furbo\craftlinkchecker;

use Craft;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Utilities;
use furbo\craftlinkchecker\models\Settings;
use furbo\craftlinkchecker\services\LinkCheckerService;
use furbo\craftlinkchecker\utilities\LinkCheckerUtility;
use yii\base\Event;

class CraftLinkChecker extends Plugin
{
    public bool $hasCpSettings = true;

    public static function getInstance(): static
    {
        return parent::getInstance();
    }

    public function init(): void
    {
        parent::init();

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'furbo\craftlinkchecker\console\controllers';
        }

        $this->setComponents([
            'linkCheckerService' => LinkCheckerService::class,
        ]);

        $utilityEvent = defined(sprintf('%s::EVENT_REGISTER_UTILITY_TYPES', Utilities::class))
            ? Utilities::EVENT_REGISTER_UTILITY_TYPES  // Craft 4
            : Utilities::EVENT_REGISTER_UTILITIES;     // Craft 5+

        Event::on(
            Utilities::class,
            $utilityEvent,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = LinkCheckerUtility::class;
            }
        );
    }

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    public function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('craft-link-checker/_settings', [
            'settings' => $this->getSettings(),
        ]);
    }
}
