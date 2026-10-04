<?php
/**
 * USMA news feed for the homepage "West Point News" section.
 *
 * Fetches the official westpoint.edu RSS feed, keeps the latest few items
 * (title, link, date), and caches them for 30 minutes in
 * usma-news-cache.json (gitignored) so the homepage never waits on
 * westpoint.edu. If a refresh fails, the last good copy is served.
 *
 * westpoint.edu's feed is wrapped in Drupal "THEME DEBUG" HTML comments —
 * before the <?xml declaration and after </rss>, which strict XML parsers
 * reject — and its <description> fields are full of the same debug markup.
 * So the wrapper is trimmed before parsing and descriptions aren't used.
 */
header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');

const USMA_FEED_URL   = 'https://www.westpoint.edu/rss.xml';
const USMA_CACHE_FILE = __DIR__ . '/usma-news-cache.json';
const USMA_CACHE_TTL  = 1800; // seconds
const USMA_MAX_ITEMS  = 6;

function usma_read_cache(): ?array {
    if (!is_file(USMA_CACHE_FILE)) return null;
    $data = json_decode((string)@file_get_contents(USMA_CACHE_FILE), true);
    return is_array($data) && !empty($data['items']) ? $data : null;
}

function usma_fetch_items(): ?array {
    $ch = curl_init(USMA_FEED_URL);
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
        error_log("usma-news: fetch failed (HTTP $code) $err");
        return null;
    }

    // Keep only <rss ...> ... </rss>; drop the debug comments around it.
    $start = strpos($body, '<rss');
    $end   = strrpos($body, '</rss>');
    if ($start === false || $end === false) {
        error_log('usma-news: no <rss> element in response');
        return null;
    }
    $xml_text = substr($body, $start, $end + 6 - $start);

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xml_text, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    if ($xml === false || !isset($xml->channel->item)) {
        error_log('usma-news: could not parse feed');
        return null;
    }

    $items = [];
    foreach ($xml->channel->item as $item) {
        $title = trim(html_entity_decode(strip_tags((string)$item->title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $link  = trim((string)$item->link);
        // Only real westpoint.edu article links (never javascript: or off-site).
        if ($title === '' || !preg_match('#^https://(www\.)?westpoint\.edu/#i', $link)) continue;
        $ts = strtotime((string)$item->pubDate);
        $items[] = [
            'title' => $title,
            'link'  => $link,
            'date'  => $ts ? date('c', $ts) : '',
        ];
        if (count($items) >= USMA_MAX_ITEMS) break;
    }
    return $items ?: null;
}

$cache = usma_read_cache();
$fresh = $cache && (time() - (int)($cache['fetched_at'] ?? 0)) < USMA_CACHE_TTL;

if (!$fresh) {
    $items = usma_fetch_items();
    if ($items) {
        $cache = ['fetched_at' => time(), 'items' => $items];
        @file_put_contents(USMA_CACHE_FILE, json_encode($cache), LOCK_EX);
    }
    // On failure, fall through and serve the stale cache if there is one.
}

if ($cache) {
    echo json_encode(['success' => true, 'items' => $cache['items'], 'source' => 'https://www.westpoint.edu/news']);
} else {
    http_response_code(503);
    echo json_encode(['success' => false, 'items' => []]);
}
