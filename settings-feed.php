<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
require_once __DIR__ . '/admin/config.php';
try {
    $pdo  = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>true, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    // Whitelist keys safe for public consumption — never dump the full table
    $public_keys = ['hero_subtitle','hero_cta_text','hero_cta_url','membership_dues',
                    'membership_description','president_letter','president_name',
                    'president_title','facebook_url','footer_resources',
                    'spotlight_name','spotlight_photo','spotlight_description',
                    'stat_current_cadets','stat_annual_events','stat_years_active',
                    'parent_letters_open'];
    $ph   = implode(',', array_fill(0, count($public_keys), '?'));
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($ph)");
    $stmt->execute($public_keys);
    $settings = [];
    foreach ($stmt->fetchAll() as $r) $settings[$r['setting_key']] = $r['setting_value'];
    echo json_encode(['success'=>true,'settings'=>$settings]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'settings'=>[]]);
    error_log('settings-feed: '.$e->getMessage());
}
