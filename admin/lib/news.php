<?php
/**
 * Shared news helpers for the homepage news sections.
 * Used by news-feed.php (on the web server) and by
 * tools/usma-news-relay.php (run by the GitHub Action
 * .github/workflows/usma-news.yml). No output, no side effects.
 */

const NEWS_MAX_ITEMS = 6;

const USMA_NEWS_PAGE         = 'https://www.westpoint.edu/news/west-point-news';
const USMA_NEWS_LINK_PATTERN = '#^https://(www\.)?westpoint\.edu/news/west-point-news/#i';

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

// westpoint.edu "West Point News" listing page → items.
// westpoint.edu has no feed for just this category (its only RSS mixes in
// sports and is wrapped in Drupal debug markup), so this reads the listing
// page: each story is a <div class="views-row"> with a .pao-news-link, a
// .pao-news-title and a summary <p>. The page has no dates. Community News
// items also listed there are skipped by the link pattern.
function news_parse_usma_page(string $html): array {
    $html  = preg_replace('/<!--.*?-->/s', '', $html); // Drupal debug comments
    $items = [];
    foreach (array_slice(preg_split('/<div class="views-row">/', $html), 1) as $row) {
        if (!preg_match('/class="[^"]*pao-news-link[^"]*"\s+href="([^"]+)"/', $row, $lm)) continue;
        if (!preg_match('/class="[^"]*pao-news-title[^"]*"[^>]*>(.*?)<\/h4>/s', $row, $tm)) continue;
        $link  = html_entity_decode(trim($lm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = news_clean_text($tm[1]);
        if ($title === '' || !preg_match(USMA_NEWS_LINK_PATTERN, $link)) continue;
        $summary = preg_match('/<\/h4>.*?<p[^>]*>(.*?)<\/p>/s', $row, $pm)
            ? news_summary(preg_replace('/\.{3,}\s*$/', '', news_clean_text($pm[1])))
            : '';
        $items[] = ['title' => $title, 'link' => $link, 'date' => '', 'summary' => $summary];
        if (count($items) >= NEWS_MAX_ITEMS) break;
    }
    return $items;
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

// Relay JSON (written by tools/usma-news-relay.php) → items, re-validated
// here so the web server never trusts the relay blindly.
function news_parse_relay(string $body, string $link_pattern): array {
    $data  = json_decode($body, true);
    $items = [];
    foreach ((is_array($data) ? ($data['items'] ?? []) : []) as $it) {
        if (!is_array($it)) continue;
        $title = news_clean_text((string)($it['title'] ?? ''));
        $link  = trim((string)($it['link'] ?? ''));
        if ($title === '' || !preg_match($link_pattern, $link)) continue;
        $items[] = [
            'title'   => mb_substr($title, 0, 300),
            'link'    => $link,
            'date'    => '',
            'summary' => news_summary((string)($it['summary'] ?? '')),
        ];
        if (count($items) >= NEWS_MAX_ITEMS) break;
    }
    return $items;
}
