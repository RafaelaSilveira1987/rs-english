<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/api.php';

require_login();

$rows = db()->query("
    SELECT s.id, s.name, s.phone, s.email, s.status,
           COALESCE(sp.overall_level,'A1') overall_level,
           COALESCE(sp.xp,0) xp,
           sp.last_study_at
    FROM students s
    LEFT JOIN student_profiles sp ON sp.student_id = s.id
    ORDER BY s.created_at DESC
")->fetchAll();

json_response(['students' => $rows]);
