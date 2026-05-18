<?php

namespace furbo\craftlinkchecker\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Entry;
use furbo\craftlinkchecker\CraftLinkChecker;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class LinkCheckerService extends Component
{
    public const STATUS_IDLE = 'idle';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETE = 'complete';

    private array $loginPathIndicators = ['login', 'anmelden', 'sign-in', 'signin'];

    private function skippedHosts(): array
    {
        return CraftLinkChecker::getInstance()->getSettings()->getSkippedHostsArray();
    }

    private function skippedPathPrefixes(): array
    {
        return CraftLinkChecker::getInstance()->getSettings()->getSkippedPathPrefixesArray();
    }

    public function getStopFilePath(): string
    {
        return Craft::getAlias('@storage') . '/craft-link-checker/stop';
    }

    public function requestStop(): void
    {
        touch($this->getStopFilePath());
    }

    public function isStopRequested(): bool
    {
        return file_exists($this->getStopFilePath());
    }

    private function clearStopRequest(): void
    {
        $path = $this->getStopFilePath();
        if (file_exists($path)) {
            unlink($path);
        }
    }

    public function getResultsFilePath(): string
    {
        return Craft::getAlias('@storage') . '/craft-link-checker/results.json';
    }

    public function getResults(): array
    {
        $path = $this->getResultsFilePath();
        if (!file_exists($path)) {
            return ['status' => self::STATUS_IDLE, 'lastRun' => null, 'results' => []];
        }
        $json = file_get_contents($path);
        return json_decode($json, true) ?: ['status' => self::STATUS_IDLE, 'lastRun' => null, 'results' => []];
    }

    public function saveResults(array $data): void
    {
        $path = $this->getResultsFilePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            array_walk_recursive($data, function(&$value) {
                if (is_string($value)) {
                    $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                }
            });
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        file_put_contents($path, $json);
    }

    public function runCheck(?callable $progressCallback = null, ?callable $verboseCallback = null, ?callable $checkpointCallback = null): array
    {
        $this->clearStopRequest();

        $client = new Client([
            'timeout' => 15,
            'connect_timeout' => 8,
            'headers' => ['User-Agent' => 'CraftLinkChecker/1.0'],
            'http_errors' => false,
        ]);

        $sites = Craft::$app->getSites()->getAllSites();

        $siteBaseUrls = [];
        foreach ($sites as $site) {
            $base = rtrim($site->getBaseUrl(), '/');
            if ($base) {
                $siteBaseUrls[$site->handle] = $base;
            }
        }

        // Auto-derive sections with no URL format (Craft 4 only — sections service removed in Craft 5)
        $urllessSectionHandles = [];
        if (version_compare(Craft::$app->version, '5.0.0', '<')) {
            foreach (Craft::$app->sections->getAllSections() as $section) {
                $hasUrls = false;
                foreach ($section->getSiteSettings() as $siteSettings) {
                    if ($siteSettings->hasUrls) {
                        $hasUrls = true;
                        break;
                    }
                }
                if (!$hasUrls) {
                    $urllessSectionHandles[] = $section->handle;
                }
            }
        }

        $total = 0;
        foreach ($sites as $site) {
            $total += Entry::find()->siteId($site->id)->status('live')->uri(':notempty:')->count();
            foreach ($urllessSectionHandles as $sectionHandle) {
                $total += Entry::find()->siteId($site->id)->status('live')->section($sectionHandle)->count();
            }
        }

        $allSiteBaseUrls = [];
        $knownLiveUrls = [];
        foreach ($sites as $site) {
            $base = rtrim($site->getBaseUrl(), '/');
            if (!$base) {
                continue;
            }
            $allSiteBaseUrls[] = $base;
            $knownLiveUrls[$base] = true;

            $rows = Entry::find()
                ->siteId($site->id)
                ->status('live')
                ->uri(':notempty:')
                ->asArray(true)
                ->all();

            foreach ($rows as $row) {
                if (!empty($row['uri'])) {
                    $knownLiveUrls[$base . '/' . ltrim($row['uri'], '/')] = true;
                }
            }
        }

        $checkedUrls = [];
        $results = [];
        $linksFound = 0;
        $processed = 0;

        foreach ($sites as $site) {
            $query = Entry::find()->siteId($site->id)->status('live')->uri(':notempty:');

            foreach ($query->each(50) as $entry) {
                if ($this->isStopRequested()) {
                    break 2;
                }

                $pageUrl = $entry->getUrl();
                if (!$pageUrl) {
                    continue;
                }

                $processed++;
                if ($progressCallback) {
                    $progressCallback($processed, $total);
                }

                $pageTitle = $entry->title ?? '(no title)';
                $siteHandle = $site->handle;
                $siteName = $site->getName();
                $cpEditUrl = $entry->getCpEditUrl();

                if ($verboseCallback) {
                    $verboseCallback('page', "[fields] {$pageUrl}");
                }
                $links = $this->extractLinksFromEntry($entry);

                if ($verboseCallback) {
                    $verboseCallback('info', "  " . count($links) . " link(s) found");
                }

                $this->processLinks(
                    $links, $pageUrl, $pageTitle, $siteHandle, $siteName, $cpEditUrl,
                    $siteBaseUrls, $allSiteBaseUrls, $knownLiveUrls,
                    $client, $checkedUrls, $results, $linksFound, $verboseCallback
                );

                if ($checkpointCallback) {
                    $checkpointCallback([
                        'status' => self::STATUS_RUNNING,
                        'lastRun' => null,
                        'lastCheckpoint' => date('c'),
                        'pagesChecked' => $processed,
                        'pagesTotal' => $total,
                        'linksFound' => $linksFound,
                        'linksChecked' => count($checkedUrls),
                        'brokenCount' => count($results),
                        'results' => $results,
                    ]);
                }
            }
        }

        // Second pass: entries in sections without a URL format
        if (!$this->isStopRequested()) {
            foreach ($sites as $site) {
                foreach ($urllessSectionHandles as $sectionHandle) {
                    $query = Entry::find()
                        ->siteId($site->id)
                        ->status('live')
                        ->section($sectionHandle);

                    foreach ($query->each(50) as $entry) {
                        if ($this->isStopRequested()) {
                            break 3;
                        }

                        $processed++;
                        if ($progressCallback) {
                            $progressCallback($processed, $total);
                        }

                        $pageTitle = $entry->title ?? '(no title)';
                        $siteHandle = $site->handle;
                        $siteName = $site->getName();
                        $cpEditUrl = $entry->getCpEditUrl();

                        if ($verboseCallback) {
                            $verboseCallback('page', "[fields] (no url) {$pageTitle}");
                        }

                        $links = $this->extractLinksFromEntry($entry);

                        if ($verboseCallback) {
                            $verboseCallback('info', "  " . count($links) . " link(s) found");
                        }

                        $this->processLinks(
                            $links, '', $pageTitle, $siteHandle, $siteName, $cpEditUrl,
                            $siteBaseUrls, $allSiteBaseUrls, $knownLiveUrls,
                            $client, $checkedUrls, $results, $linksFound, $verboseCallback
                        );

                        if ($checkpointCallback) {
                            $checkpointCallback([
                                'status' => self::STATUS_RUNNING,
                                'lastRun' => null,
                                'lastCheckpoint' => date('c'),
                                'pagesChecked' => $processed,
                                'pagesTotal' => $total,
                                'linksFound' => $linksFound,
                                'linksChecked' => count($checkedUrls),
                                'brokenCount' => count($results),
                                'results' => $results,
                            ]);
                        }
                    }
                }
            }
        }

        $stopped = $this->isStopRequested();
        $this->clearStopRequest();

        return [
            'status' => $stopped ? 'stopped' : self::STATUS_COMPLETE,
            'lastRun' => date('c'),
            'pagesChecked' => $processed,
            'pagesTotal' => $total,
            'linksFound' => $linksFound,
            'linksChecked' => count($checkedUrls),
            'brokenCount' => count($results),
            'results' => $results,
        ];
    }

    // ── Link checking ─────────────────────────────────────────────────────────

    private function processLinks(
        array $links,
        string $sourcePage,
        string $sourceTitle,
        string $siteHandle,
        string $siteName,
        string $cpEditUrl,
        array $siteBaseUrls,
        array $allSiteBaseUrls,
        array $knownLiveUrls,
        Client $client,
        array &$checkedUrls,
        array &$results,
        int &$linksFound,
        ?callable $verboseCallback,
    ): void {
        $linksFound += count($links);

        foreach ($links as $linkUrl) {
            try {
                $isInternal = $this->isInternalUrl($linkUrl, $siteBaseUrls);
                $linkType = $isInternal ? 'internal' : 'external';

                if (isset($checkedUrls[$linkUrl])) {
                    $check = $checkedUrls[$linkUrl];
                } else {
                    $isPrivateInternal = false;
                    foreach ($allSiteBaseUrls as $base) {
                        if (str_starts_with($linkUrl, $base)) {
                            $isPrivateInternal = true;
                            break;
                        }
                    }

                    if ($isPrivateInternal) {
                        $exists = isset($knownLiveUrls[rtrim($linkUrl, '/')]);
                        $check = ['status' => $exists ? 200 : 404, 'error' => null];
                    } else {
                        if ($verboseCallback) {
                            $verboseCallback('check', "  → {$linkUrl}");
                        }
                        $check = $this->checkUrl($linkUrl, $client);
                    }
                    $checkedUrls[$linkUrl] = $check;
                }

                if ($check['skipped'] ?? false) {
                    continue;
                }

                $status = $check['status'] ?? null;
                $isOk = $status !== null && $status >= 200 && $status < 300;

                if (!$isOk) {
                    $statusLabel = $status ?? 'ERR';
                    if ($verboseCallback) {
                        $verboseCallback('broken', "  ✗ [{$statusLabel}] {$linkUrl}");
                    }
                    $results[] = [
                        'sourcePage' => $sourcePage,
                        'sourceTitle' => $sourceTitle,
                        'sourceSite' => $siteHandle,
                        'sourceSiteName' => $siteName,
                        'cpEditUrl' => $cpEditUrl,
                        'linkUrl' => $linkUrl,
                        'linkType' => $linkType,
                        'httpStatus' => $status,
                        'error' => $check['error'] ?? null,
                        'isOk' => false,
                    ];
                }
            } catch (\Throwable $e) {
                if ($verboseCallback) {
                    $verboseCallback('error', "  ! Exception for {$linkUrl}: " . $e->getMessage());
                }
            }
        }
    }

    // ── Field-based extraction ────────────────────────────────────────────────

    private function extractLinksFromEntry(Entry $entry): array
    {
        return $this->extractLinksFromElement($entry);
    }

    private function extractLinksFromElement(ElementInterface $element): array
    {
        $links = [];
        $fieldLayout = $element->getFieldLayout();
        if (!$fieldLayout) {
            return $links;
        }

        foreach ($fieldLayout->getCustomFields() as $field) {
            try {
                $value = $element->getFieldValue($field->handle);
            } catch (\Throwable) {
                continue;
            }
            $links = array_merge($links, $this->extractLinksFromFieldValue($field, $value, $element));
        }

        return $links;
    }

    private function extractLinksFromFieldValue(FieldInterface $field, mixed $value, ElementInterface $element): array
    {
        $links = [];

        // Redactor rich-text (Craft 4)
        if (class_exists(\craft\redactor\Field::class) && $field instanceof \craft\redactor\Field) {
            $html = (string) $value;
            if ($html !== '') {
                $baseUrl = $element->getUrl() ?? '';
                $links = array_merge($links, $this->extractLinksFromHtml($html, $baseUrl));
            }
            return $links;
        }

        // CKEditor rich-text (Craft 5)
        if (class_exists(\craft\ckeditor\Field::class) && $field instanceof \craft\ckeditor\Field) {
            $html = (string) $value;
            if ($html !== '') {
                $baseUrl = $element->getUrl() ?? '';
                $links = array_merge($links, $this->extractLinksFromHtml($html, $baseUrl));
            }
            return $links;
        }

        // URL field (value may be a string or a stringable object in Craft 5)
        if ($field instanceof \craft\fields\Url) {
            $url = (string) $value;
            if ($url !== '') {
                $normalized = $this->normalizeUrl($url, 'https', '', '/');
                if ($normalized) {
                    $links[] = $normalized;
                }
            }
            return $links;
        }

        // Matrix
        if ($field instanceof \craft\fields\Matrix) {
            foreach ($value->all() as $block) {
                $links = array_merge($links, $this->extractLinksFromElement($block));
            }
            return $links;
        }

        // Super Table (verbb/super-table)
        if (
            class_exists(\verbb\supertable\fields\SuperTableField::class) &&
            $field instanceof \verbb\supertable\fields\SuperTableField
        ) {
            foreach ($value->all() as $block) {
                $links = array_merge($links, $this->extractLinksFromElement($block));
            }
            return $links;
        }

        return $links;
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────────

    private function isLoginUrl(string $url): bool
    {
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
        foreach ($this->loginPathIndicators as $indicator) {
            if (str_contains($path, $indicator)) {
                return true;
            }
        }
        return false;
    }

    private function extractLinksFromHtml(string $html, string $pageUrl): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $parsed = parse_url($pageUrl);
        $baseScheme = $parsed['scheme'] ?? 'https';
        $baseHost = $parsed['host'] ?? '';
        $basePort = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $basePath = dirname($parsed['path'] ?? '/');

        $links = [];
        foreach ($dom->getElementsByTagName('a') as $node) {
            $href = trim($node->getAttribute('href'));
            $normalized = $this->normalizeUrl($href, $baseScheme, $baseHost . $basePort, $basePath);
            if ($normalized) {
                $links[] = $normalized;
            }
        }

        return array_unique($links);
    }

    private function normalizeUrl(string $url, string $scheme, string $host, string $basePath): ?string
    {
        if (
            $url === '' ||
            str_starts_with($url, '#') ||
            str_starts_with($url, 'javascript:') ||
            str_starts_with($url, 'mailto:') ||
            str_starts_with($url, 'tel:') ||
            str_starts_with($url, 'data:')
        ) {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            $pos = strpos($url, '#');
            return $pos !== false ? substr($url, 0, $pos) : $url;
        }

        if (str_starts_with($url, '//')) {
            return $scheme . ':' . $url;
        }

        if (str_starts_with($url, '/')) {
            return $scheme . '://' . $host . $url;
        }

        if ($host === '') {
            return null;
        }

        return $scheme . '://' . $host . rtrim($basePath, '/') . '/' . $url;
    }

    private function isInternalUrl(string $url, array $siteBaseUrls): bool
    {
        foreach ($siteBaseUrls as $baseUrl) {
            if (str_starts_with($url, $baseUrl)) {
                return true;
            }
        }
        return false;
    }

    private function isSkippedUrl(string $url): bool
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        foreach ($this->skippedHosts() as $skipped) {
            if ($host === $skipped || str_ends_with($host, '.' . $skipped)) {
                return true;
            }
        }

        $path = parse_url($url, PHP_URL_PATH) ?? '';
        foreach ($this->skippedPathPrefixes() as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function encodeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts) {
            return $url;
        }

        if (!empty($parts['host']) && preg_match('/[^\x00-\x7F]/', $parts['host'])) {
            if (function_exists('idn_to_ascii')) {
                $ascii = idn_to_ascii($parts['host'], IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
                if ($ascii !== false) {
                    $parts['host'] = $ascii;
                }
            }
        }

        if (!empty($parts['path'])) {
            $parts['path'] = implode('/', array_map(
                fn($seg) => rawurlencode(rawurldecode($seg)),
                explode('/', $parts['path'])
            ));
        }

        $encoded = ($parts['scheme'] ?? 'https') . '://';
        if (!empty($parts['user'])) {
            $encoded .= $parts['user'] . (!empty($parts['pass']) ? ':' . $parts['pass'] : '') . '@';
        }
        $encoded .= $parts['host'] ?? '';
        if (!empty($parts['port'])) {
            $encoded .= ':' . $parts['port'];
        }
        $encoded .= $parts['path'] ?? '';
        if (!empty($parts['query'])) {
            $encoded .= '?' . $parts['query'];
        }
        if (!empty($parts['fragment'])) {
            $encoded .= '#' . $parts['fragment'];
        }

        return $encoded;
    }

    private function checkUrl(string $url, Client $client): array
    {
        if ($this->isSkippedUrl($url)) {
            return ['status' => null, 'error' => null, 'skipped' => true];
        }

        $requestUrl = $this->encodeUrl($url);

        try {
            $response = $client->head($requestUrl, [
                'allow_redirects' => ['max' => 5],
            ]);
            $status = $response->getStatusCode();

            if ($status === 405) {
                $response = $client->get($requestUrl, [
                    'allow_redirects' => ['max' => 5],
                ]);
                $status = $response->getStatusCode();
            }

            return ['status' => $status, 'error' => null];
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                return ['status' => $e->getResponse()->getStatusCode(), 'error' => null];
            }
            $msg = $e->getMessage();
            if (strlen($msg) > 120) {
                $msg = substr($msg, 0, 120) . '…';
            }
            return ['status' => null, 'error' => $msg];
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (strlen($msg) > 120) {
                $msg = substr($msg, 0, 120) . '…';
            }
            return ['status' => null, 'error' => $msg];
        }
    }
}
