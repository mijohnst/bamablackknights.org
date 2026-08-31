<?php
header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');
require_once __DIR__ . '/admin/config.php';
try {
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>true, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);

    // Whole-section manual off switch, set from admin/gallery.php. Defaults
    // to visible if the setting has never been touched.
    $section_visible = true;
    try {
        $sv = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='gallery_section_visible'")->fetchColumn();
        if ($sv !== false) $section_visible = (bool)(int)$sv;
    } catch (Exception $e) { /* setting not present yet — stay visible */ }

    if (!$section_visible) {
        // Explicit false (not just an empty photos array) so the front end
        // can tell "admin turned this off" apart from "no photos yet" —
        // the latter is what triggers the heavier Google Drive fallback
        // script, which hiding the section should skip entirely, not load.
        echo json_encode(['success'=>true,'sectionVisible'=>false,'photos'=>[]]);
        exit;
    }

    $rows = $pdo->query("SELECT * FROM site_photos WHERE active=1 ORDER BY sort_order ASC, id ASC")->fetchAll();
    echo json_encode(['success'=>true,'sectionVisible'=>true,'photos'=>$rows]);
} catch (Exception $e) { http_response_code(500); echo json_encode(['success'=>false,'photos'=>[]]); error_log('photos-feed: '.$e->getMessage()); }
