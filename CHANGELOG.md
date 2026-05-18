# Changelog

## 1.0.0 - 2026-05-18

### Added
- Initial release
- Crawls all live entries across all Craft CMS sites and verifies every link is reachable
- Two-pass crawl: entries with frontend URLs, then URL-less section entries
- Extracts links from Redactor rich-text fields and URL fields, recursively inside Matrix and Super Table blocks
- Internal links resolved via DB lookup (no HTTP request)
- External links verified via Guzzle HEAD request (GET fallback on 405)
- Deduplication cache: each unique URL is checked only once
- Results persisted to `storage/craft-link-checker/results.json`
- CP utility at **Utilities → Link Checker**: run, monitor progress, stop, browse results, export CSV
- Settings page at **Settings → Link Checker**: configurable skipped hosts and path prefixes
- CLI command `php craft link-checker/link-checker/run` for scheduled/headless runs
- Background queue job support
- Memory-efficient batch processing (50 entries at a time)
