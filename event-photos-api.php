<?php
// Public JSON API for the Club Events gallery page.
// No authentication required — only returns visible albums, their photos, and their documents.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

// Allow cross-origin requests from same-site only (not needed for same-domain, but safe)
// header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/admin/config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database unavailable']);
    exit;
}

$action = $_GET['action'] ?? 'albums';

if ($action === 'albums') {
    // Return all visible albums with photo count, doc count, and cover photo filename
    $rows = $pdo->query("
        SELECT a.id, a.name, a.event_date, a.description, a.sort_order,
               a.location_name, a.location_url,
               (SELECT COUNT(*) FROM event_photos    WHERE album_id=a.id) AS photo_count,
               (SELECT COUNT(*) FROM event_documents WHERE album_id=a.id) AS doc_count,
               cp.filename AS cover_filename
        FROM event_albums a
        LEFT JOIN event_photos cp ON cp.id = a.cover_photo_id
        WHERE a.visible = 1
          AND ((SELECT COUNT(*) FROM event_photos    WHERE album_id=a.id) > 0
            OR (SELECT COUNT(*) FROM event_documents WHERE album_id=a.id) > 0)
        ORDER BY a.sort_order ASC, a.id DESC
    ")->fetchAll();

    $albums = [];
    foreach ($rows as $r) {
        $loc_url = $r['location_url'];
        if ($loc_url && !preg_match('/^https?:\/\//i', $loc_url)) $loc_url = null; // re-validate on output too
        $albums[] = [
            'id'            => (int)$r['id'],
            'name'          => $r['name'],
            'event_date'    => $r['event_date'],
            'description'   => $r['description'],
            'location_name' => $r['location_name'],
            'location_url'  => $loc_url,
            'photo_count'   => (int)$r['photo_count'],
            'doc_count'     => (int)$r['doc_count'],
            'cover_url'     => $r['cover_filename'] ? '/event-photo-serve.php?f=' . rawurlencode($r['cover_filename']) : null,
        ];
    }
    echo json_encode(['albums' => $albums]);

} elseif ($action === 'photos') {
    $album_id = (int)($_GET['album_id'] ?? 0);
    if (!$album_id) { echo json_encode(['error' => 'Missing album_id']); exit; }

    $chk = $pdo->prepare('SELECT id FROM event_albums WHERE id=? AND visible=1');
    $chk->execute([$album_id]);
    if (!$chk->fetch()) { echo json_encode(['photos' => []]); exit; }

    $rows = $pdo->prepare('SELECT id, filename, caption, sort_order FROM event_photos WHERE album_id=? ORDER BY sort_order ASC, id ASC');
    $rows->execute([$album_id]);

    $photos = [];
    foreach ($rows->fetchAll() as $p) {
        $photos[] = [
            'id'      => (int)$p['id'],
            'url'     => '/event-photo-serve.php?f=' . rawurlencode($p['filename']),
            'caption' => $p['caption'],
        ];
    }
    echo json_encode(['photos' => $photos]);

} elseif ($action === 'docs') {
    $album_id = (int)($_GET['album_id'] ?? 0);
    if (!$album_id) { echo json_encode(['error' => 'Missing album_id']); exit; }

    $chk = $pdo->prepare('SELECT id FROM event_albums WHERE id=? AND visible=1');
    $chk->execute([$album_id]);
    if (!$chk->fetch()) { echo json_encode(['docs' => []]); exit; }

    $rows = $pdo->prepare('SELECT id, filename, original_name, label, type, url, sort_order FROM event_documents WHERE album_id=? ORDER BY sort_order ASC, id ASC');
    $rows->execute([$album_id]);

    $docs = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $is_link = ($d['type'] === 'link');
        if ($is_link && !preg_match('/^https?:\/\//i', $d['url'])) continue; // re-validate on output too
        $docs[] = [
            'id'   => (int)$d['id'],
            'url'  => $is_link ? $d['url'] : '/event-doc-serve.php?f=' . rawurlencode($d['filename']),
            'name' => $d['label'] !== '' ? $d['label'] : $d['original_name'],
            'ext'  => $is_link ? 'link' : strtolower(pathinfo($d['filename'], PATHINFO_EXTENSION)),
        ];
    }
    echo json_encode(['docs' => $docs]);

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
}
