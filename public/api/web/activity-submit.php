<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/auth.php';
require_once __DIR__ . '/../../../src/portal.php';
require_once __DIR__ . '/../../../src/config.php';
require_once __DIR__ . '/../../../src/progress.php';
require_once __DIR__ . '/../../../src/learning.php';

header('Content-Type: application/json; charset=utf-8');

$user = require_student();
$payload = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$studentActivityId = trim((string)($payload['student_activity_id'] ?? ''));
$answer = trim((string)($payload['answer'] ?? ''));
$durationSeconds = max(0, (int)round((float)($payload['duration_seconds'] ?? 0)));
$correctAnswers = isset($payload['correct_answers']) && is_numeric($payload['correct_answers']) ? max(0, (int)$payload['correct_answers']) : null;
$totalQuestions = isset($payload['total_questions']) && is_numeric($payload['total_questions']) ? max(0, (int)$payload['total_questions']) : null;

if ($studentActivityId === '' || $answer === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Atividade e resposta são obrigatórias.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(<<<'SQL'
        SELECT
            sa.id,
            sa.status,
            sa.attempts,
            a.title,
            a.activity_type,
            a.skill,
            a.level,
            a.instructions,
            a.content,
            a.xp_reward,
            a.estimated_minutes
        FROM student_activities sa
        JOIN activities a ON a.id = sa.activity_id
        WHERE sa.id = :id
          AND sa.student_id = :student_id
        FOR UPDATE
    SQL);
    $stmt->execute(['id' => $studentActivityId, 'student_id' => $user['student_id']]);
    $activity = $stmt->fetch();

    if (!$activity) throw new RuntimeException('Atividade não encontrada.');
    if ($activity['status'] === 'completed') throw new RuntimeException('Esta atividade já foi concluída.');

    $content = portal_json($activity['content'] ?? null, []);
    $score = null;
    $feedback = '';
    $evaluation = [];

    $evaluationUrl = trim((string)env('N8N_WEB_ACTIVITY_URL', ''));
    if ($evaluationUrl !== '') {
        $request = [
            'student_id' => $user['student_id'],
            'name' => $user['name'],
            'phone' => $user['phone'],
            'activity' => [
                'id' => $studentActivityId,
                'title' => $activity['title'],
                'type' => $activity['activity_type'],
                'skill' => $activity['skill'],
                'level' => $activity['level'],
                'instructions' => $activity['instructions'],
                'content' => $content,
            ],
            'answer' => $answer,
        ];

        $ch = curl_init($evaluationUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . (string)env('N8N_API_KEY', ''),
            ],
            CURLOPT_POSTFIELDS => json_encode($request, JSON_UNESCAPED_UNICODE),
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body !== false && $curlError === '' && $status >= 200 && $status < 300) {
            $evaluation = json_decode($body, true) ?: [];
            $score = isset($evaluation['score']) ? max(0, min(100, (float)$evaluation['score'])) : null;
            $feedback = portal_clean_text($evaluation['feedback'] ?? '');
        }
    }

    if ($score === null) {
        $accepted = portal_json($content['accepted_answers'] ?? [], []);
        $correct = trim((string)($content['correct_answer'] ?? ''));
        $normalizedAnswer = mb_strtolower(preg_replace('/\s+/', ' ', trim($answer)));
        $acceptedNormalized = array_map(
            fn($item) => mb_strtolower(preg_replace('/\s+/', ' ', trim((string)$item))),
            $accepted
        );

        if ($correct !== '') {
            $isCorrect = hash_equals(mb_strtolower(trim($correct)), $normalizedAnswer);
            $score = $isCorrect ? 100.0 : 40.0;
            $feedback = $isCorrect
                ? 'Resposta correta. Continue usando essa estrutura em novas frases.'
                : 'Resposta registrada. Revise a instrução e compare com a forma esperada: ' . $correct;
        } elseif ($acceptedNormalized) {
            $isCorrect = in_array($normalizedAnswer, $acceptedNormalized, true);
            $score = $isCorrect ? 100.0 : 40.0;
            $feedback = $isCorrect
                ? 'Resposta correta. Muito bem!'
                : 'Boa tentativa. Revise o conteúdo e tente aplicar a estrutura em outra frase.';
        } else {
            $wordCount = str_word_count($answer);
            $score = $wordCount >= 4 ? 85.0 : 70.0;
            $feedback = $wordCount >= 4
                ? 'Resposta concluída. A Emma usará essa produção para acompanhar seu desenvolvimento.'
                : 'Resposta concluída. Na próxima tentativa, procure responder com uma frase um pouco mais completa.';
        }
    }

    $attemptNumber = (int)$activity['attempts'] + 1;
    $xp = (int)$activity['xp_reward'];

    $attempt = $pdo->prepare(<<<'SQL'
        INSERT INTO activity_attempts(
            student_id, student_activity_id, attempt_number,
            answer_text, answer_data, score, feedback, evaluation_data,
            skill_code, difficulty, duration_seconds,
            correct_answers, total_questions, source
        ) VALUES(
            :student_id, :student_activity_id, :attempt_number,
            :answer_text, CAST(:answer_data AS jsonb), :score, :feedback,
            CAST(:evaluation_data AS jsonb), :skill_code, :difficulty,
            :duration_seconds, :correct_answers, :total_questions, 'web'
        )
        RETURNING id
    SQL);
    $attempt->execute([
        'student_id' => $user['student_id'],
        'student_activity_id' => $studentActivityId,
        'attempt_number' => $attemptNumber,
        'answer_text' => $answer,
        'answer_data' => json_encode(['answer' => $answer], JSON_UNESCAPED_UNICODE),
        'score' => $score,
        'feedback' => $feedback,
        'evaluation_data' => json_encode($evaluation, JSON_UNESCAPED_UNICODE),
        'skill_code' => learning_normalize_skill((string)$activity['skill']),
        'difficulty' => (string)$activity['level'],
        'duration_seconds' => $durationSeconds > 0 ? $durationSeconds : max(0, (int)$activity['estimated_minutes'] * 60),
        'correct_answers' => $correctAnswers,
        'total_questions' => $totalQuestions,
    ]);
    $attemptId = $attempt->fetchColumn();

    $pdo->prepare(<<<'SQL'
        UPDATE student_activities
        SET status = 'completed',
            started_at = COALESCE(started_at, NOW()),
            completed_at = NOW(),
            last_attempt_at = NOW(),
            attempts = :attempts,
            answer_text = :answer_text,
            answer_data = CAST(:answer_data AS jsonb),
            score = :score,
            feedback = :feedback,
            xp_earned = :xp
        WHERE id = :id
    SQL)->execute([
        'attempts' => $attemptNumber,
        'answer_text' => $answer,
        'answer_data' => json_encode(['attempt_id' => $attemptId], JSON_UNESCAPED_UNICODE),
        'score' => $score,
        'feedback' => $feedback,
        'xp' => $xp,
        'id' => $studentActivityId,
    ]);

    $pdo->prepare(<<<'SQL'
        UPDATE student_profiles
        SET xp = COALESCE(xp, 0) + :xp,
            last_study_at = NOW(),
            updated_at = NOW()
        WHERE student_id = :student_id
    SQL)->execute(['xp' => $xp, 'student_id' => $user['student_id']]);

    [$weekStart, $weekEnd] = portal_week_bounds();
    $pdo->prepare(<<<'SQL'
        INSERT INTO weekly_goals(
            student_id, week_start, week_end,
            completed_minutes, completed_activities
        ) VALUES(
            :student_id, :week_start, :week_end, :minutes, 1
        )
        ON CONFLICT(student_id, week_start)
        DO UPDATE SET
            completed_minutes = weekly_goals.completed_minutes + EXCLUDED.completed_minutes,
            completed_activities = weekly_goals.completed_activities + 1,
            updated_at = NOW()
    SQL)->execute([
        'student_id' => $user['student_id'],
        'week_start' => $weekStart->format('Y-m-d'),
        'week_end' => $weekEnd->format('Y-m-d'),
        'minutes' => (int)$activity['estimated_minutes'],
    ]);

    $effectiveDuration = $durationSeconds > 0
        ? $durationSeconds
        : max(0, (int)$activity['estimated_minutes'] * 60);

    $skillEvaluation = $evaluation;
    if (learning_extract_skill_scores($skillEvaluation) === []) {
        $activitySkill = learning_normalize_skill((string)$activity['skill']);
        if ($activitySkill !== null) {
            $skillEvaluation[$activitySkill . '_score'] = $score;
        }
    }

    $recordedSkills = learning_record_evaluation(
        $pdo,
        (string)$user['student_id'],
        $skillEvaluation,
        [
            'source' => 'activity',
            'event_prefix' => learning_event_key('activity-skill', [(string)$attemptId]),
            'source_id' => (string)$attemptId,
            'student_activity_id' => $studentActivityId,
            'message_type' => 'text',
            'weight' => 2.0,
            'confidence' => $evaluation['confidence_score'] ?? null,
            'evidence_text' => $answer,
            'evidence_data' => [
                'activity_title' => $activity['title'],
                'activity_level' => $activity['level'],
                'correct_answers' => $correctAnswers,
                'total_questions' => $totalQuestions,
            ],
        ]
    );

    $vocabularySaved = learning_sync_vocabulary(
        $pdo,
        (string)$user['student_id'],
        learning_vocabulary_items($evaluation),
        [
            'source' => 'activity',
            'level' => (string)$activity['level'],
            'source_context' => ['student_activity_id' => $studentActivityId, 'attempt_id' => (string)$attemptId],
        ]
    );

    $evaluationCorrections = $evaluation['corrections'] ?? $evaluation['errors'] ?? [];
    $correctionsSaved = is_array($evaluationCorrections)
        ? learning_sync_corrections(
            $pdo,
            (string)$user['student_id'],
            $evaluationCorrections,
            [
                'channel' => 'web_activity',
                'event_prefix' => learning_event_key('activity-correction', [(string)$attemptId]),
            ]
        )
        : 0;

    learning_record_event(
        $pdo,
        (string)$user['student_id'],
        learning_event_key('activity', [$studentActivityId]),
        'activity_completed',
        'web',
        null,
        $studentActivityId,
        $effectiveDuration,
        $score,
        $xp,
        [
            'attempt_id' => $attemptId,
            'title' => $activity['title'],
            'skill' => $activity['skill'],
            'level' => $activity['level'],
            'skills_recorded' => array_keys($recordedSkills),
            'corrections_saved' => $correctionsSaved,
            'vocabulary_saved' => count($vocabularySaved),
            'correct_answers' => $correctAnswers,
            'total_questions' => $totalQuestions,
        ]
    );

    portal_record_event(
        (string)$user['student_id'],
        'activity',
        'Atividade concluída',
        (string)$activity['title'],
        ['score' => $score, 'xp' => $xp],
        $studentActivityId
    );

    $pdo->commit();
    progress_refresh_after_event((string)$user['student_id']);

    echo json_encode([
        'success' => true,
        'score' => round((float)$score),
        'feedback' => $feedback,
        'xp_earned' => $xp,
        'skills_recorded' => array_keys($recordedSkills),
        'corrections_saved' => $correctionsSaved,
        'vocabulary_saved' => count($vocabularySaved),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
