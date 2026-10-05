<?php
/**
 * News feeds for the homepage:
 *   news-feed.php?source=usma   → "West Point News"   (westpoint.edu/news/west-point-news)
 *   news-feed.php?source=wpaog  → "WPAOG News"         (westpointaog.org News Room)
 *
 * Fetches the source (an RSS feed, or for USMA its news listing page),
 * keeps the latest few items, and caches them for 30 minutes in
 * news-cache-<source>.json (gitignored) so the homepage never waits on the
 * other site. If a refresh fails, the last good copy is served. Only these
 * two whitelisted sources can be fetched.
 *
 * USMA: westpoint.edu has no feed for just the "West Point News" category
 * (its only RSS mixes in sports and is wrapped in Drupal debug markup), so
 * this reads the category's listing page instead: each story is a
 * <div class="views-row"> with a .pao-news-link, a .pao-news-title and a
 * summary <p>. The page has no dates, so USMA items carry none. If the page
 * layout changes and nothing parses, the last good cache keeps serving and
 * the failure is logged ("news-feed: ... parsed 0 stories").
 * WPAOG: the News Room's WordPress RSS feed, with clean summaries.
 */
header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');

const NEWS_SOURCES = [
    'usma' => [
        'type'         => 'page',
        'feed'         => 'https://www.westpoint.edu/news/west-point-news',
        'link_pattern' => '#^https://(www\.)?westpoint\.edu/news/west-point-news/#i',
        'more'         => 'https://www.westpoint.edu/news/west-point-news',
        'summaries'    => true,
    ],
    'wpaog' => [
        'type'         => 'rss',
        'feed'         => 'https://www.westpointaog.org/feed/',
        'link_pattern' => '#^https://(www\.)?westpointaog\.org/#i',
        'more'         => 'https://www.westpointaog.org/news/news-room/',
        'summaries'    => true,
    ],
];
const NEWS_CACHE_TTL = 1800; // seconds
const NEWS_MAX_ITEMS = 6;

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

function news_clean_text(string $s): string {
    $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $s));
}

function news_summary(string $s, int $max = 170): string {
    $s = news_clean_text($s);
    $s = preg_replace('/\s*(\[(&hellip;|…|\.\.\.)\]|The post .* appeared first on .*)$/u', '', $s); // WordPress tails
    if (mb_strlen($s) <= $max) return $s;
    $cut = mb_substr($s, 0, $max);
    $sp  = mb_strrpos($cut, ' ');
    return rtrim(mb_substr($cut, 0, $sp ?: $max), " ,.;:-") . '…';
}

function news_http_get(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; WPPC-Alabama-News/1.0; +https://bamablackknights.org/)',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code !== 200) {
        error_log("news-feed: fetch failed for $url (HTTP $code) $err");
        return null;
    }
    return $body;
}

// USMA listing page → items (see the header comment for the markup it expects).
function news_parse_usma_page(string $html, array $source): array {
    $html  = preg_replace('/<!--.*?-->/s', '', $html); // Drupal debug comments
    $items = [];
    foreach (array_slice(preg_split('/<div class="views-row">/', $html), 1) as $row) {
        if (!preg_match('/class="[^"]*pao-news-link[^"]*"\s+href="([^"]+)"/', $row, $lm)) continue;
        if (!preg_match('/class="[^"]*pao-news-title[^"]*"[^>]*>(.*?)<\/h4>/s', $row, $tm)) continue;
        $link  = html_entity_decode(trim($lm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = news_clean_text($tm[1]);
        if ($title === '' || !preg_match($source['link_pattern'], $link)) continue;
        $summary = preg_match('/<\/h4>.*?<p[^>]*>(.*?)<\/p>/s', $row, $pm)
            ? news_summary(preg_replace('/\.{3,}\s*$/', '', news_clean_text($pm[1])))
            : '';
        $items[] = ['title' => $title, 'link' => $link, 'date' => '', 'summary' => $summary];
        if (count($items) >= NEWS_MAX_ITEMS) break;
    }
    return $items;
}

function news_fetch_items(array $source): ?array {
    $body = news_http_get($source['feed']);
    if ($body === null) return null;

    if ($source['type'] === 'page') {
        $items = news_parse_usma_page($body, $source);
        if (!$items) error_log("news-feed: {$source['feed']} parsed 0 stories (page layout changed?)");
        return $items ?: null;
    }

    // Keep only <rss ...> ... </rss>; drop anything wrapped around it.
    $start = strpos($body, '<rss');
    $end   = strrpos($body, '</rss>');
    if ($start === false || $end === false) {
        error_log("news-feed: no <rss> element in {$source['feed']}");
        return null;
    }
    $xml_text = substr($body, $start, $end + 6 - $start);

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xml_text, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    if ($xml === false || !isset($xml->channel->item)) {
        error_log("news-feed: could not parse {$source['feed']}");
        return null;
    }

    $items = [];
    foreach ($xml->channel->item as $item) {
        $title = news_clean_text((string)$item->title);
        $link  = trim((string)$item->link);
        // Only real article links on the source's own site (never javascript: or off-site).
        if ($title === '' || !preg_match($source['link_pattern'], $link)) continue;
        $ts = strtotime((string)$item->pubDate);
        $row = ['title' => $title, 'link' => $link, 'date' => $ts ? date('c', $ts) : ''];
        if ($source['summaries']) $row['summary'] = news_summary((string)$item->description);
        $items[] = $row;
        if (count($items) >= NEWS_MAX_ITEMS) break;
    }
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
