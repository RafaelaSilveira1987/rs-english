<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/db.php';
require_once __DIR__ . '/../../../src/api.php';
require_once __DIR__ . '/../../../src/portal.php';
require_once __DIR__ . '/../../../src/progress.php';
require_once __DIR__ . '/../../../src/learning.php';

require_n8n_key();

$data = json_input();
$studentId = trim((string)($data['student_id'] ?? ''));
$phone = normalize_phone($data['phone'] ?? '');
$studentActivityId = trim((string)($data['student_activity_id'] ?? ''));
$score = learning_clamp($data['score'] ?? 0);
$feedback = portal_clean_text($data['feedback'] ?? '');
$answerText = trim((string)($data['answer_text'] ?? $data['answer'] ?? ''));
$answerData = is_array($data['answer_data'] ?? null) ? $data['answer_data'] : [];
$evaluation = is_array($data['evaluation'] ?? null) ? $data['evaluation'] : [];
$durationSeconds = max(0, (int)round((float)($data['duration_seconds'] ?? 0)));
$correctAnswers = isset($data['correct_answers']) && is_numeric($data['correct_answers'])
    ? max(0, (int)$data['correct_answers'])
    : null;
$totalQuestions = isset($data['total_questions']) && is_numeric($data['total_questions'])
    ? max(0, (int)$data['total_questions'])
    : null;
$source = trim((string)($data['source'] ?? 'n8n')) ?: 'n8n';

if ($studentActivityId === '' || ($studentId === '' && $phone === '')) {
    json_response([
        'success' => false,
        'error' => 'student_activity_id e student_id ou phone são obrigatórios.',
    ], 422);
}

$pdo = db();

try {
    $pdo->beginTransaction();

    if ($studentId === '') {
        $query = $pdo->prepare(<<<'SQL'
            SELECT id
            FROM students
            WHERE regexp_replace(COALESCE(phone, ''), '[^0-9]', '', 'g') = :phone
            LIMIT 1
        SQL);
        $query->execute(['phone' => $phone]);
        $studentId = (string)($query->fetchColumn() ?: '');
    }

    if ($studentId === '') {
        throw new RuntimeException('Aluno não encontrado.');
    }

    $query = $pdo->prepare(<<<'SQL'
        SELECT
            sa.id,
            sa.status,
            COALESCE(sa.attempts, 0) AS attempts,
            sa.completed_at,
            a.id AS activity_id,
            a.title,
            a.skill,
            a.level,
            COALESCE(a.xp_reward, 0) AS xp_reward,
            COALESCE(a.estimated_minutes, 0) AS estimated_minutes
        FROM student_activities sa
        JOIN activities a ON a.id = sa.activity_id
        WHERE sa.id = :id
          AND sa.student_id = :student_id
        FOR UPDATE
    SQL);
    $query->execute([
        'id' => $studentActivityId,
        'student_id' => $studentId,
    ]);
    $activity = $query->fetch();

    if (!$activity) {
        throw new RuntimeException('Atividade não encontrada para este aluno.');
    }

    if ((string)$activity['status'] === 'completed') {
        $pdo->commit();
        progress_refresh_after_event($studentId);
        json_response([
            'success' => true,
            'already_completed' => true,
            'student_id' => $studentId,
            'student_activity_id' => $studentActivityId,
            'message' => 'A atividade já estava concluída; XP e meta não foram duplicados.',
        ]);
    }

    $attemptNumber = (int)$activity['attempts'] + 1;
    $xp = max(0, (int)$activity['xp_reward']);
    $estimatedSeconds = max(0, (int)$activity['estimated_minutes'] * 60);
    if ($durationSeconds === 0) {
        $durationSeconds = $estimatedSeconds;
    }

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
            :duration_seconds, :correct_answers, :total_questions, :source
        )
        RETURNING id
    SQL);
    $attempt->execute([
        'student_id' => $studentId,
        'student_activity_id' => $studentActivityId,
        'attempt_number' => $attemptNumber,
        'answer_text' => $answerText !== '' ? $answerText : null,
        'answer_data' => learning_json($answerData),
        'score' => $score,
        'feedback' => $feedback !== '' ? $feedback : null,
        'evaluation_data' => learning_json($evaluation),
        'skill_code' => learning_normalize_skill((string)$activity['skill']) ?: null,
        'difficulty' => (string)($data['difficulty'] ?? $activity['level'] ?? ''),
        'duration_seconds' => $durationSeconds,
        'correct_answers' => $correctAnswers,
        'total_questions' => $totalQuestions,
        'source' => mb_strimwidth($source, 0, 30, ''),
    ]);
    $attemptId = (string)$attempt->fetchColumn();

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
        'answer_text' => $answerText !== '' ? $answerText : null,
        'answer_data' => learning_json(array_merge($answerData, ['attempt_id' => $attemptId])),
        'score' => $score,
        'feedback' => $feedback !== '' ? $feedback : null,
        'xp' => $xp,
        'id' => $studentActivityId,
    ]);

    $pdo->prepare(<<<'SQL'
        UPDATE student_profiles
        SET xp = COALESCE(xp, 0) + :xp,
            last_study_at = NOW(),
            updated_at = NOW()
        WHERE student_id = :student_id
    SQL)->execute([
        'xp' => $xp,
        'student_id' => $studentId,
    ]);

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
        'student_id' => $studentId,
        'week_start' => $weekStart->format('Y-m-d'),
        'week_end' => $weekEnd->format('Y-m-d'),
        'minutes' => max(0, (int)round($durationSeconds / 60)),
    ]);

    $skillEvaluation = $evaluation;
    if (learning_extract_skill_scores($skillEvaluation) === []) {
        $activitySkill = learning_normalize_skill((string)$activity['skill']);
        if ($activitySkill !== null) {
            $skillEvaluation[$activitySkill . '_score'] = $score;
        }
    }

    $recordedSkills = learning_record_evaluation(
        $pdo,
        $studentId,
        $skillEvaluation,
        [
            'source' => 'activity',
            'event_prefix' => learning_event_key('activity-skill', [$attemptId]),
            'source_id' => $attemptId,
            'student_activity_id' => $studentActivityId,
            'message_type' => 'text',
            'weight' => 2.0,
            'confidence' => $evaluation['confidence_score'] ?? null,
            'evidence_text' => $answerText,
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
        $studentId,
        learning_vocabulary_items($evaluation),
        [
            'source' => 'activity',
            'level' => (string)$activity['level'],
            'source_context' => ['student_activity_id' => $studentActivityId, 'attempt_id' => $attemptId],
        ]
    );

    $corrections = $evaluation['corrections'] ?? $evaluation['errors'] ?? [];
    $correctionsSaved = is_array($corrections)
        ? learning_sync_corrections(
            $pdo,
            $studentId,
            $corrections,
            [
                'channel' => 'activity',
                'event_prefix' => learning_event_key('activity-correction', [$attemptId]),
            ]
        )
        : 0;

    learning_record_event(
        $pdo,
        $studentId,
        learning_event_key('activity', [$studentActivityId]),
        'activity_completed',
        $source,
        null,
        $studentActivityId,
        $durationSeconds,
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
        $studentId,
        'activity',
        'Atividade concluída',
        (string)$activity['title'],
        ['score' => $score, 'xp' => $xp, 'attempt_id' => $attemptId],
        $studentActivityId
    );

    $pdo->commit();
    progress_refresh_after_event($studentId);

    json_response([
        'success' => true,
        'student_id' => $studentId,
        'student_activity_id' => $studentActivityId,
        'attempt_id' => $attemptId,
        'score' => $score,
        'xp_earned' => $xp,
        'skills_recorded' => array_keys($recordedSkills),
        'corrections_saved' => $correctionsSaved,
        'vocabulary_saved' => count($vocabularySaved),
    ], 201);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[COMPLETE ACTIVITY] ' . $exception->getMessage());
    json_response([
        'success' => false,
        'error' => $exception->getMessage(),
    ], 500);
}
