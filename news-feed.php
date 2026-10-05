<?php
/**
 * News feeds for the homepage:
 *   news-feed.php?source=cadets → "Cadet News"  (WPAOG News Room, Cadet News category)
 *   news-feed.php?source=wpaog  → "WPAOG News"  (WPAOG News Room, all news)
 *
 * Reads the source's RSS feed, keeps the latest few items, and caches them
 * for 30 minutes in news-cache-<source>.json (gitignored) so the homepage
 * never waits on the other site. If a refresh fails, the last good copy is
 * served. Only these whitelisted feeds can be fetched.
 *
 * Why WPAOG for cadet news: westpoint.edu returns HTTP 403 to this hosting
 * server (and to GitHub's runners) — it blocks data-center networks — so it
 * can't be read from here. westpointaog.org can.
 */
header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');

require_once __DIR__ . '/admin/lib/news.php';

const NEWS_SOURCES = [
    'cadets' => [
        'feed'         => 'https://www.westpointaog.org/news/news-category/cadet-news/feed/',
        'link_pattern' => '#^https://(www\.)?westpointaog\.org/#i',
        'more'         => 'https://www.westpointaog.org/news/news-category/cadet-news/',
    ],
    'wpaog' => [
        'feed'         => 'https://www.westpointaog.org/feed/',
        'link_pattern' => '#^https://(www\.)?westpointaog\.org/#i',
        'more'         => 'https://www.westpointaog.org/news/news-room/',
    ],
];
const NEWS_CACHE_TTL = 1800; // seconds

$source_key = $_GET['source'] ?? 'wpaog';
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
    $items = news_parse_rss($body, $source['link_pattern'], true);
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
