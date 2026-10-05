<?php
/**
 * News feeds for the homepage:
 *   news-feed.php?source=usma   → "West Point News"   (westpoint.edu/news/west-point-news, via GitHub relay)
 *   news-feed.php?source=wpaog  → "WPAOG News"         (westpointaog.org News Room RSS)
 *
 * Keeps the latest few items and caches them for 30 minutes in
 * news-cache-<source>.json (gitignored) so the homepage never waits on the
 * other site. If a refresh fails, the last good copy is served. Only these
 * two whitelisted sources can be fetched.
 *
 * USMA: westpoint.edu returns 403 to this hosting server's IP (it blocks
 * data-center addresses), so the server can't read it directly. Instead the
 * GitHub Action .github/workflows/usma-news.yml reads the West Point News
 * page every 3 hours and commits the stories to the repo's `news-data`
 * branch; this script reads that JSON from raw.githubusercontent.com and
 * re-validates every item. Parsing logic lives in admin/lib/news.php.
 * WPAOG: the News Room's WordPress RSS feed, with clean summaries.
 */
header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');

require_once __DIR__ . '/admin/lib/news.php';

const NEWS_SOURCES = [
    'usma' => [
        'type'         => 'relay',
        'feed'         => 'https://raw.githubusercontent.com/mijohnst/bamablackknights.org/news-data/usma-news.json',
        'link_pattern' => USMA_NEWS_LINK_PATTERN,
        'more'         => USMA_NEWS_PAGE,
    ],
    'wpaog' => [
        'type'         => 'rss',
        'feed'         => 'https://www.westpointaog.org/feed/',
        'link_pattern' => '#^https://(www\.)?westpointaog\.org/#i',
        'more'         => 'https://www.westpointaog.org/news/news-room/',
    ],
];
const NEWS_CACHE_TTL = 1800; // seconds

$source_key = $_GET['source'] ?? 'usma';
if (!isset(NEWS_SOURCES[$source_key])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'items' => []]);
    exit;
}
$source     = NEWS_SOURCES[$source_key];
$cache_file = __DIR__ . '/news-cache-' . $source_key . '.json';

function news_read_cache(string $file): ?array {
    if (!is_file($file)) return null;
    $data = json_decode((string)@file_get_contents($file), true);
    return is_array($data) && !empty($data['items']) ? $data : null;
}

function news_fetch_items(array $source): ?array {
    $body = news_http_get($source['feed']);
    if ($body === null) return null;
    $items = $source['type'] === 'relay'
        ? news_parse_relay($body, $source['link_pattern'])
        : news_parse_rss($body, $source['link_pattern'], true);
    if (!$items) error_log("news-feed: {$source['feed']} gave 0 usable stories");
    return $items ?: null;
}

$cache = news_read_cache($cache_file);
$fresh = $cache && (time() - (int)($cache['fetched_at'] ?? 0)) < NEWS_CACHE_TTL;

if (!$fresh) {
    $items = news_fetch_items($source);
    if ($items) {
        $cache = ['fetched_at' => time(), 'items' => $items];
        @file_put_contents($cache_file, json_encode($cache), LOCK_EX);
    }
    // On failure, fall through and serve the stale cache if there is one.
}

if ($cache) {
    echo json_encode(['success' => true, 'items' => $cache['items'], 'more' => $source['more']]);
} else {
    http_response_code(503);
    echo json_encode(['success' => false, 'items' => []]);
}
