<?php
/**
 * Shared helpers for the homepage news sections (used by news-feed.php).
 * No output, no side effects.
 *
 * History: westpoint.edu was tried first, but it returns HTTP 403 to the
 * hosting server and to GitHub's runners (it blocks data-center networks),
 * so both sections now read westpointaog.org feeds, which the server can reach.
 */

const NEWS_MAX_ITEMS = 6;

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

function news_http_get(string $url, int $timeout = 8): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $timeout,
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

// Any RSS 2.0 feed → items. Trims anything wrapped around <rss>…</rss>.
function news_parse_rss(string $body, string $link_pattern, bool $summaries): array {
    $start = strpos($body, '<rss');
    $end   = strrpos($body, '</rss>');
    if ($start === false || $end === false) return [];
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string(substr($body, $start, $end + 6 - $start), 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    if ($xml === false || !isset($xml->channel->item)) return [];

    $items = [];
    foreach ($xml->channel->item as $item) {
        $title = news_clean_text((string)$item->title);
        $link  = trim((string)$item->link);
        // Only real article links on the source's own site (never javascript: or off-site).
        if ($title === '' || !preg_match($link_pattern, $link)) continue;
        $ts  = strtotime((string)$item->pubDate);
        $row = ['title' => $title, 'link' => $link, 'date' => $ts ? date('c', $ts) : ''];
        if ($summaries) $row['summary'] = news_summary((string)$item->description);
        $items[] = $row;
        if (count($items) >= NEWS_MAX_ITEMS) break;
    }
    return $items;
}
