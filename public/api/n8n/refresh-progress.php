<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/db.php';
require_once __DIR__ . '/../../../src/api.php';
require_once __DIR__ . '/../../../src/progress.php';
require_once __DIR__ . '/../../../src/learning.php';

require_n8n_key();

$data = json_input();
$studentId = trim((string)($data['student_id'] ?? ''));
$activeOnly = !array_key_exists('active_only', $data) || filter_var($data['active_only'], FILTER_VALIDATE_BOOL);
$limit = max(1, min(5000, (int)($data['limit'] ?? 1000)));
$pdo = db();

try {
    if ($studentId !== '') {
        learning_recalculate_profile_skills($pdo, $studentId);
        $metrics = progress_student_metrics($studentId, true);
        if (!$metrics) {
            json_response(['success' => false, 'error' => 'Aluno não encontrado.'], 404);
        }

        json_response([
            'success' => true,
            'processed' => 1,
            'student_id' => $studentId,
            'snapshot_date' => date('Y-m-d'),
        ]);
    }

    $sql = 'SELECT id FROM students';
    if ($activeOnly) {
        $sql .= " WHERE status = 'active'";
    }
    $sql .= ' ORDER BY created_at LIMIT :limit';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $processed = 0;
    $failed = [];

    foreach ($ids as $id) {
        try {
            $id = (string)$id;
            learning_recalculate_profile_skills($pdo, $id);
            if (progress_student_metrics($id, true)) {
                $processed++;
            }
        } catch (Throwable $studentError) {
            $failed[] = [
                'student_id' => (string)$id,
                'error' => mb_strimwidth($studentError->getMessage(), 0, 300, '…'),
            ];
        }
    }

    json_response([
        'success' => $failed === [],
        'processed' => $processed,
        'failed_count' => count($failed),
        'failed' => array_slice($failed, 0, 20),
        'snapshot_date' => date('Y-m-d'),
    ], $failed === [] ? 200 : 207);
} catch (Throwable $exception) {
    error_log('[REFRESH PROGRESS] ' . $exception->getMessage());
    json_response([
        'success' => false,
        'error' => 'Não foi possível atualizar os indicadores.',
        'details' => (string)env('APP_ENV', 'production') !== 'production'
            ? $exception->getMessage()
            : null,
    ], 500);
}
