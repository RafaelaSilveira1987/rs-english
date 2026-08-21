<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/auth.php';
require_once __DIR__ . '/../../../src/portal.php';
require_once __DIR__ . '/../../../src/progress.php';

header('Content-Type: application/json; charset=utf-8');
$user = require_student();
$payload = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];

$minutes = max(15, min(1000, (int)($payload['target_minutes'] ?? 100)));
$activities = max(1, min(20, (int)($payload['target_activities'] ?? 4)));
$words = max(3, min(100, (int)($payload['target_words'] ?? 20)));
[$weekStart, $weekEnd] = portal_week_bounds();

$pdo = db();
$pdo->prepare(<<<'SQL'
    INSERT INTO weekly_goals(
        student_id, week_start, week_end,
        target_minutes, target_activities, target_words, target_source
    ) VALUES(
        :student_id, :week_start, :week_end,
        :target_minutes, :target_activities, :target_words, 'student'
    )
    ON CONFLICT(student_id, week_start)
    DO UPDATE SET
        week_end = EXCLUDED.week_end,
        target_minutes = EXCLUDED.target_minutes,
        target_activities = EXCLUDED.target_activities,
        target_words = EXCLUDED.target_words,
        target_source = 'student',
        updated_at = NOW()
SQL)->execute([
    'student_id' => $user['student_id'],
    'week_start' => $weekStart->format('Y-m-d'),
    'week_end' => $weekEnd->format('Y-m-d'),
    'target_minutes' => $minutes,
    'target_activities' => $activities,
    'target_words' => $words,
]);

$metrics = progress_student_metrics((string)$user['student_id'], true);
echo json_encode(['success' => true, 'week' => $metrics['week'] ?? []], JSON_UNESCAPED_UNICODE);
