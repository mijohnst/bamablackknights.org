<?php
header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');
require_once __DIR__ . '/admin/config.php';
require_once __DIR__ . '/admin/lib.php'; // dependency-free — for job_drop_eligible_year()
try {
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>true, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    // Whole-section manual off switch — separate from the per-class
    // auto-retirement below, for when an officer just wants to turn the
    // whole thing off right now rather than hide entries one at a time.
    // Defaults to visible if the table isn't migrated yet or has no row.
    $section_visible = true;
    try {
        $sv = $pdo->query("SELECT section_visible FROM job_drop_settings WHERE id=1")->fetchColumn();
        if ($sv !== false) $section_visible = (bool)$sv;
    } catch (Exception $e) { /* table not migrated yet — stay visible */ }

    if (!$section_visible) {
        echo json_encode(['success'=>true,'eligibleYear'=>job_drop_eligible_year($pdo),'drops'=>[]]);
        exit;
    }

    // Only ever shows the currently-eligible class's entries (see
    // job_drop_eligible_year() in admin/lib.php) so a prior class's
    // approved photos automatically stop appearing here once the next
    // class becomes eligible, with no manual cleanup needed between years.
    $eligible_year = job_drop_eligible_year($pdo);
    $stmt = $pdo->prepare("SELECT filename, cadet_name, job_title, youtube_id FROM job_drop_photos WHERE active=1 AND class_year=? ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$eligible_year]);
    $rows = $stmt->fetchAll();
    echo json_encode(['success'=>true,'eligibleYear'=>$eligible_year,'drops'=>$rows]);
} catch (Exception $e) { http_response_code(500); echo json_encode(['success'=>false,'eligibleYear'=>null,'drops'=>[]]); error_log('job-drop-feed: '.$e->getMessage()); }
