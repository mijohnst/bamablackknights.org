<?php
/**
 * USAFA News Feed Fetcher (Fixed Version)
 * This script fetches the USAFA RSS feed and caches it to improve performance
 * Handles redirects and protocol mismatches
 */

// Set headers for JSON response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: max-age=1800'); // 30 minutes

// Cache settings
$cacheFile = dirname(__FILE__) . '/usafa-news-cache.json';
$cacheTime = 1800; // Cache for 30 minutes (1800 seconds)

// Returns the cache file's content only if it's non-empty and contains real news items, else null
function readValidCache($cacheFile) {
    if (!file_exists($cacheFile)) return null;
    $content = file_get_contents($cacheFile);
    $decoded = json_decode($content, true);
    if ($content === '' || !is_array($decoded) || empty($decoded['items'])) return null;
    return $content;
}

// Check if cache exists, is still valid, and actually contains usable JSON
$cacheContent = readValidCache($cacheFile);
if ($cacheContent !== null && (time() - filemtime($cacheFile)) < $cacheTime) {
    // Serve from cache with proper headers
    header('X-Cache: hit');
    echo $cacheContent;
    exit;
}

// Cache expired or doesn't exist - fetch fresh data
$feedUrl = 'https://www.usafa.edu/feed/';

// Initialize cURL with comprehensive options
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $feedUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch, CURLOPT_USERAGENT, 'Alabama USAFA Parents Club Website');
curl_setopt($ch, CURLOPT_ENCODING, 'gzip,deflate');

// Add IPv4 preference
curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

$xmlContent = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Check if fetch was successful
if ($httpCode != 200 || !$xmlContent) {
    error_log("USAFA Feed Error - HTTP Code: $httpCode, cURL Error: $curlError");
    
    // If fetch failed, try to serve old cache if it exists
    $staleCache = readValidCache($cacheFile);
    if ($staleCache !== null) {
        header('X-Cache: stale');
        echo $staleCache;
    } else {
        http_response_code(503);
        echo json_encode([
            'error' => 'Unable to fetch news feed',
            'httpCode' => $httpCode,
            'details' => $curlError
        ]);
    }
    exit;
}

// Parse XML - trim whitespace/BOM that can break simplexml
$xmlContent = trim($xmlContent);
$xmlContent = preg_replace('/^\xEF\xBB\xBF/', '', $xmlContent); // Strip UTF-8 BOM

$xml = @simplexml_load_string($xmlContent);

if (!$xml) {
    error_log("USAFA Feed XML Parse Error - Content starts with: " . substr($xmlContent, 0, 200));
    error_log("USAFA Feed XML Parse Error - Content length: " . strlen($xmlContent));
    
    // Try to serve cache on parse failure
    $staleCache = readValidCache($cacheFile);
    if ($staleCache !== null) {
        header('X-Cache: stale');
        echo $staleCache;
    } else {
        http_response_code(502);
        echo json_encode(['error' => 'Unable to parse RSS feed']);
    }
    exit;
}

// Extract news items
$newsItems = [];
$count = 0;

foreach ($xml->channel->item as $item) {
    if ($count >= 4) break; // Only get 4 articles
    
    $title = (string)$item->title;
    $link = (string)$item->link;
    $pubDate = (string)$item->pubDate;
    
    // Clean description - remove HTML tags
    $description = strip_tags((string)$item->description);
    $description = html_entity_decode($description);
    $description = preg_replace('/\s+/', ' ', $description);
    $description = trim($description);
    
    // Limit excerpt length
    if (strlen($description) > 180) {
        $description = substr($description, 0, 180) . '...';
    }
    
    // Only add if we have a title
    if ($title) {
        $newsItems[] = [
            'title' => $title,
            'link' => $link,
            'pubDate' => $pubDate,
            'description' => $description
        ];
        $count++;
    }
}

// If we got no items, serve stale cache instead of empty response
if (empty($newsItems)) {
    error_log("USAFA Feed: XML parsed OK but 0 items extracted");
    $staleCache = readValidCache($cacheFile);
    if ($staleCache !== null) {
        header('X-Cache: stale-empty-parse');
        echo $staleCache;
        exit;
    }
}

// Create JSON response
$response = [
    'status' => 'ok',
    'items' => $newsItems,
    'cached_at' => date('Y-m-d H:i:s'),
    'fetched_at' => date('Y-m-d H:i:s')
];

// JSON_INVALID_UTF8_SUBSTITUTE keeps a single malformed byte in the RSS feed
// (which simplexml won't sanitize) from failing the entire encode
$jsonResponse = json_encode($response, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);

// Save to cache only if we got valid items and encoding actually succeeded
if (!empty($newsItems) && $jsonResponse !== false) {
    @file_put_contents($cacheFile, $jsonResponse);
} elseif ($jsonResponse === false) {
    error_log("USAFA Feed: json_encode failed - " . json_last_error_msg());
}

// Output response
header('X-Cache: miss');
echo $jsonResponse;
?>
