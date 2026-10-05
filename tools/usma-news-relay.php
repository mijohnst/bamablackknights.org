<?php
/**
 * West Point News relay — run by .github/workflows/usma-news.yml (not on
 * the web server). westpoint.edu blocks the hosting server's IP, so GitHub
 * reads the page instead and commits the result to the `news-data` branch,
 * where news-feed.php picks it up.
 *
 * Usage: php tools/usma-news-relay.php <output.json>
 * Exits 0 and leaves the file untouched when the stories haven't changed.
 * Exits 1 (job shows as failed, old file kept) if the page can't be read
 * or yields no stories — e.g. westpoint.edu redesigned it.
 */
require __DIR__ . '/../admin/lib/news.php';

$out = $argv[1] ?? '';
if ($out === '') { fwrite(STDERR, "usage: php tools/usma-news-relay.php <output.json>\n"); exit(2); }

$html = news_http_get(USMA_NEWS_PAGE, 30);
if ($html === null) { fwrite(STDERR, "Could not fetch " . USMA_NEWS_PAGE . "\n"); exit(1); }

$items = news_parse_usma_page($html);
if (!$items) { fwrite(STDERR, "Parsed 0 stories from " . USMA_NEWS_PAGE . " — page layout may have changed.\n"); exit(1); }

$old = is_file($out) ? json_decode((string)file_get_contents($out), true) : null;
if (is_array($old) && ($old['items'] ?? null) === $items) {
    echo "Unchanged (" . count($items) . " stories).\n";
    exit(0);
}

$json = json_encode([
    'source'     => USMA_NEWS_PAGE,
    'updated_at' => gmdate('c'),
    'items'      => $items,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
file_put_contents($out, $json);
echo "Wrote " . count($items) . " stories:\n";
foreach ($items as $it) echo " - {$it['title']}\n";
