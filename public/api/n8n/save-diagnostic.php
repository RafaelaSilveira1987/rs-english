<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/db.php';
require_once __DIR__ . '/../../../src/api.php';

require_n8n_key();

/**
 * Executa uma etapa auxiliar sem cancelar o salvamento principal.
 * O PostgreSQL exige rollback até um SAVEPOINT após qualquer erro SQL.
 */
function diagnostic_optional_step(
    PDO $pdo,
    string $savepoint,
    string $label,
    callable $callback,
    array &$warnings
): void {
    $safeSavepoint = preg_replace('/[^a-z0-9_]/i', '_', $savepoint) ?: 'optional_step';

    $pdo->exec('SAVEPOINT ' . $safeSavepoint);

    try {
        $callback();
        $pdo->exec('RELEASE SAVEPOINT ' . $safeSavepoint);
    } catch (Throwable $exception) {
        $pdo->exec('ROLLBACK TO SAVEPOINT ' . $safeSavepoint);
        $pdo->exec('RELEASE SAVEPOINT ' . $safeSavepoint);

        $warnings[] = $label;
        error_log(
            '[RS ENGLISH DIAGNOSTIC OPTIONAL] '
            . $label
            . ' | '
            . get_class($exception)
            . ' | '
            . $exception->getMessage()
        );
    }
}

$data = json_input();

$phone = normalize_phone($data['phone'] ?? '');
$name = trim((string)($data['student_name'] ?? 'Aluno'));
$studentMessage = trim((string)($data['student_message'] ?? ''));
$teacherMessage = trim((string)($data['teacher_message'] ?? ''));
$rawMessageType = strtolower(trim((string)($data['message_type'] ?? 'text')));
$messageType = str_contains($rawMessageType, 'audio') ? 'audio' : 'text';
$diagnostic = is_array($data['diagnostic'] ?? null) ? $data['diagnostic'] : [];

if ($phone === '') {
    json_response(['error' => 'phone é obrigatório'], 422);
}

if ($name === '') {
    $name = 'Aluno';
}

$nextStep = max(0, (int)($diagnostic['next_step'] ?? 1));
$complete = !empty($diagnostic['complete']);
$level = strtoupper(trim((string)($diagnostic['estimated_level'] ?? 'PRE-A1')));

$allowedLevels = ['PRE-A1', 'A1', 'A2', 'B1', 'B2', 'C1', 'C2'];
if (!in_array($level, $allowedLevels, true)) {
    $level = 'PRE-A1';
}

$languageSupport = strtolower(trim((string)($diagnostic['language_support'] ?? 'adaptive')));
if (!in_array($languageSupport, ['portuguese', 'adaptive', 'english'], true)) {
    $languageSupport = 'adaptive';
}

$scores = is_array($diagnostic['scores'] ?? null) ? $diagnostic['scores'] : [];
$strengths = is_array($diagnostic['strengths'] ?? null) ? $diagnostic['strengths'] : [];
$weaknesses = is_array($diagnostic['weaknesses'] ?? null) ? $diagnostic['weaknesses'] : [];
$recommendations = is_array($diagnostic['recommendations'] ?? null) ? $diagnostic['recommendations'] : [];
$studyPlan = is_array($diagnostic['study_plan'] ?? null) ? $diagnostic['study_plan'] : [];
$firstActivity = is_array($diagnostic['first_activity'] ?? null) ? $diagnostic['first_activity'] : [];
$corrections = is_array($diagnostic['corrections'] ?? null) ? $diagnostic['corrections'] : [];

/*
 * Não passe booleanos PHP diretamente no execute() do PDO PgSQL.
 * Em algumas configurações, false é convertido em string vazia e o PostgreSQL
 * responde "invalid input syntax for type boolean".
 */
$preA1DatabaseValue = $level === 'PRE-A1' ? 'true' : 'false';

$pdo = db();
$stage = 'initializing';
$errorReference = 'diag-' . bin2hex(random_bytes(5));
$warnings = [];

try {
    $pdo->beginTransaction();

    $stage = 'finding_student';

    $query = $pdo->prepare("
        SELECT id
        FROM students
        WHERE regexp_replace(COALESCE(phone, ''), '[^0-9]', '', 'g') = :phone
        LIMIT 1
    ");
    $query->execute(['phone' => $phone]);
    $studentId = $query->fetchColumn();

    if (!$studentId) {
        $stage = 'creating_student';

        $query = $pdo->prepare("
            INSERT INTO students (name, phone)
            VALUES (:name, :phone)
            ON CONFLICT DO NOTHING
            RETURNING id
        ");
        $query->execute([
            'name' => $name,
            'phone' => $phone,
        ]);
        $studentId = $query->fetchColumn();

        if (!$studentId) {
            $query = $pdo->prepare("
                SELECT id
                FROM students
                WHERE regexp_replace(COALESCE(phone, ''), '[^0-9]', '', 'g') = :phone
                LIMIT 1
            ");
            $query->execute(['phone' => $phone]);
            $studentId = $query->fetchColumn();
        }
    }

    if (!$studentId) {
        throw new RuntimeException('Não foi possível localizar ou criar o aluno.');
    }

    $stage = 'ensuring_student_profile';

    $query = $pdo->prepare("
        SELECT 1
        FROM student_profiles
        WHERE student_id = :student_id
        LIMIT 1
    ");
    $query->execute(['student_id' => $studentId]);
    $profileExists = (bool)$query->fetchColumn();

    if (!$profileExists) {
        $query = $pdo->prepare("
            INSERT INTO student_profiles (
                student_id,
                overall_level,
                estimated_level,
                goal,
                correction_mode,
                diagnostic_status,
                diagnostic_step,
                diagnostic_started_at,
                preferred_language_support,
                pre_a1
            )
            VALUES (
                :student_id,
                'PRE-A1',
                'PRE-A1',
                'Aprender inglês',
                'balanced',
                'in_progress',
                0,
                NOW(),
                :language_support,
                CAST(:pre_a1 AS boolean)
            )
        ");
        $query->execute([
            'student_id' => $studentId,
            'language_support' => $languageSupport,
            'pre_a1' => 'true',
        ]);
    }

    $stage = 'finding_diagnostic_session';

    $query = $pdo->prepare("
        SELECT id
        FROM sessions
        WHERE student_id = :student_id
          AND status = 'active'
          AND mode = 'assessment'
          AND created_at >= NOW() - INTERVAL '24 hours'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $query->execute(['student_id' => $studentId]);
    $sessionId = $query->fetchColumn();

    if (!$sessionId) {
        $stage = 'creating_diagnostic_session';

        $query = $pdo->prepare("
            INSERT INTO sessions (
                student_id,
                channel,
                mode,
                topic,
                level,
                status
            )
            VALUES (
                :student_id,
                :channel,
                'assessment',
                'initial_diagnostic',
                :level,
                'active'
            )
            RETURNING id
        ");
        $query->execute([
            'student_id' => $studentId,
            'channel' => $messageType === 'audio' ? 'whatsapp_voice' : 'whatsapp',
            'level' => $level,
        ]);
        $sessionId = $query->fetchColumn();
    }

    if (!$sessionId) {
        throw new RuntimeException('Não foi possível criar a sessão de diagnóstico.');
    }

    if ($studentMessage !== '') {
        $stage = 'saving_student_message';

        $query = $pdo->prepare("
            INSERT INTO messages (
                session_id,
                student_id,
                role,
                message_type,
                content,
                transcription
            )
            VALUES (
                :session_id,
                :student_id,
                'student',
                :message_type,
                :content,
                :transcription
            )
        ");
        $query->execute([
            'session_id' => $sessionId,
            'student_id' => $studentId,
            'message_type' => $messageType,
            'content' => $studentMessage,
            'transcription' => $messageType === 'audio' ? $studentMessage : null,
        ]);
    }

    if ($teacherMessage !== '') {
        $stage = 'saving_teacher_message';

        $query = $pdo->prepare("
            INSERT INTO messages (
                session_id,
                student_id,
                role,
                message_type,
                content
            )
            VALUES (
                :session_id,
                :student_id,
                'teacher',
                'text',
                :content
            )
        ");
        $query->execute([
            'session_id' => $sessionId,
            'student_id' => $studentId,
            'content' => $teacherMessage,
        ]);
    }

    if ($corrections !== []) {
        diagnostic_optional_step(
            $pdo,
            'sp_diagnostic_corrections',
            'correções do diagnóstico não foram registradas',
            static function () use (
                $pdo,
                $corrections,
                $studentId,
                $sessionId,
                $messageType
            ): void {
                $insertCorrection = $pdo->prepare("
                    INSERT INTO correction_events (
                        student_id,
                        session_id,
                        channel,
                        correction_type,
                        original_text,
                        corrected_text,
                        explanation,
                        target_word,
                        detected_word,
                        confidence_score,
                        accepted
                    )
                    VALUES (
                        :student_id,
                        :session_id,
                        :channel,
                        :correction_type,
                        :original_text,
                        :corrected_text,
                        :explanation,
                        :target_word,
                        :detected_word,
                        :confidence_score,
                        CAST(:accepted AS boolean)
                    )
                ");

                foreach (array_slice($corrections, 0, 5) as $correction) {
                    if (!is_array($correction)) {
                        continue;
                    }

                    $insertCorrection->execute([
                        'student_id' => $studentId,
                        'session_id' => $sessionId,
                        'channel' => $messageType === 'audio' ? 'whatsapp_voice' : 'whatsapp',
                        'correction_type' => (string)(
                            $correction['correction_type']
                            ?? ($messageType === 'audio' ? 'spoken_transcript' : 'written')
                        ),
                        'original_text' => $correction['original_text'] ?? null,
                        'corrected_text' => $correction['corrected_text'] ?? null,
                        'explanation' => $correction['explanation'] ?? null,
                        'target_word' => $correction['target_word'] ?? null,
                        'detected_word' => $correction['detected_word'] ?? null,
                        'confidence_score' => isset($correction['confidence_score'])
                            ? max(0, min(100, (float)$correction['confidence_score']))
                            : null,
                        'accepted' => array_key_exists('accepted', $correction)
                            && $correction['accepted'] === false
                                ? 'false'
                                : 'true',
                    ]);
                }
            },
            $warnings
        );
    }

    if (!$complete) {
        $stage = 'updating_diagnostic_progress';

        $query = $pdo->prepare("
            UPDATE student_profiles
            SET
                diagnostic_status = 'in_progress',
                diagnostic_step = :step,
                estimated_level = :level,
                preferred_language_support = :language_support,
                pre_a1 = CAST(:pre_a1 AS boolean),
                diagnostic_started_at = COALESCE(diagnostic_started_at, NOW()),
                last_study_at = NOW(),
                updated_at = NOW()
            WHERE student_id = :student_id
        ");
        $query->execute([
            'step' => $nextStep,
            'level' => $level,
            'language_support' => $languageSupport,
            'pre_a1' => $preA1DatabaseValue,
            'student_id' => $studentId,
        ]);

        $pdo->commit();

        json_response([
            'success' => true,
            'complete' => false,
            'student_id' => $studentId,
            'session_id' => $sessionId,
            'next_step' => $nextStep,
            'estimated_level' => $level,
            'warnings' => $warnings,
        ], 201);
    }

    $grammar = (float)($scores['grammar'] ?? 0);
    $vocabulary = (float)($scores['vocabulary'] ?? 0);
    $interaction = (float)($scores['interaction'] ?? 0);
    $production = (float)($scores['production'] ?? 0);
    $reception = (float)($scores['reception'] ?? 0);
    $speaking = (float)($scores['speaking'] ?? ($interaction > 0 || $production > 0 ? ($interaction + $production) / 2 : 0));
    $listening = (float)($scores['listening'] ?? $reception);
    $reading = (float)($scores['reading'] ?? $reception);
    $writing = (float)($scores['writing'] ?? $production);
    $fluency = (float)($scores['fluency'] ?? 0);
    $pronunciation = isset($scores['pronunciation']) && $scores['pronunciation'] !== null
        ? (float)$scores['pronunciation']
        : 0.0;

    $stage = 'completing_student_profile';

    $query = $pdo->prepare("
        UPDATE student_profiles
        SET
            overall_level = :level,
            estimated_level = :level,
            diagnostic_status = 'completed',
            diagnostic_step = :step,
            diagnostic_completed_at = NOW(),
            preferred_language_support = :language_support,
            pre_a1 = CAST(:pre_a1 AS boolean),
            grammar_score = :grammar,
            vocabulary_score = :vocabulary,
            speaking_score = :speaking,
            listening_score = :listening,
            reading_score = :reading,
            writing_score = :writing,
            fluency_score = :fluency,
            pronunciation_score = :pronunciation,
            last_study_at = NOW(),
            updated_at = NOW()
        WHERE student_id = :student_id
    ");
    $query->execute([
        'level' => $level,
        'step' => $nextStep,
        'language_support' => $languageSupport,
        'pre_a1' => $preA1DatabaseValue,
        'grammar' => $grammar,
        'vocabulary' => $vocabulary,
        'speaking' => $speaking,
        'listening' => $listening,
        'reading' => $reading,
        'writing' => $writing,
        'fluency' => $fluency,
        'pronunciation' => $pronunciation,
        'student_id' => $studentId,
    ]);

    $total = round((
        $grammar
        + $vocabulary
        + $speaking
        + $listening
        + $reading
        + $writing
        + $fluency
    ) / 7, 2);

    diagnostic_optional_step(
        $pdo,
        'sp_assessment_result',
        'resultado da avaliação não foi registrado',
        static function () use (
            $pdo,
            $studentId,
            $level,
            $grammar,
            $vocabulary,
            $speaking,
            $listening,
            $reading,
            $writing,
            $fluency,
            $total,
            $strengths,
            $weaknesses,
            $recommendations,
            $diagnostic
        ): void {
            $query = $pdo->prepare("
                SELECT id
                FROM assessments
                WHERE assessment_type = 'initial_diagnostic'
                LIMIT 1
            ");
            $query->execute();
            $assessmentId = $query->fetchColumn();

            if (!$assessmentId) {
                $query = $pdo->prepare("
                    INSERT INTO assessments (title, assessment_type, level, active)
                    VALUES ('Diagnóstico Inicial', 'initial_diagnostic', :level, TRUE)
                    RETURNING id
                ");
                $query->execute(['level' => $level]);
                $assessmentId = $query->fetchColumn();
            }

            if (!$assessmentId) {
                throw new RuntimeException('Não foi possível obter a avaliação inicial.');
            }

            $query = $pdo->prepare("
                INSERT INTO assessment_results (
                    assessment_id,
                    student_id,
                    overall_level,
                    grammar_score,
                    vocabulary_score,
                    speaking_score,
                    listening_score,
                    reading_score,
                    writing_score,
                    fluency_score,
                    total_score,
                    strengths,
                    weaknesses,
                    recommendations,
                    evaluator_feedback
                )
                VALUES (
                    :assessment_id,
                    :student_id,
                    :level,
                    :grammar,
                    :vocabulary,
                    :speaking,
                    :listening,
                    :reading,
                    :writing,
                    :fluency,
                    :total,
                    CAST(:strengths AS jsonb),
                    CAST(:weaknesses AS jsonb),
                    CAST(:recommendations AS jsonb),
                    :feedback
                )
            ");
            $query->execute([
                'assessment_id' => $assessmentId,
                'student_id' => $studentId,
                'level' => $level,
                'grammar' => $grammar,
                'vocabulary' => $vocabulary,
                'speaking' => $speaking,
                'listening' => $listening,
                'reading' => $reading,
                'writing' => $writing,
                'fluency' => $fluency,
                'total' => $total,
                'strengths' => json_encode($strengths, JSON_UNESCAPED_UNICODE),
                'weaknesses' => json_encode($weaknesses, JSON_UNESCAPED_UNICODE),
                'recommendations' => json_encode($recommendations, JSON_UNESCAPED_UNICODE),
                'feedback' => (string)($diagnostic['feedback'] ?? ''),
            ]);
        },
        $warnings
    );

    diagnostic_optional_step(
        $pdo,
        'sp_diagnostic_report',
        'relatório detalhado do diagnóstico não foi registrado',
        static function () use (
            $pdo,
            $studentId,
            $level,
            $diagnostic,
            $strengths,
            $weaknesses,
            $recommendations,
            $teacherMessage,
            $studyPlan,
            $firstActivity,
            $messageType,
            $scores
        ): void {
            $query = $pdo->prepare("
                INSERT INTO diagnostic_reports (
                    student_id,
                    estimated_level,
                    confidence_score,
                    strengths,
                    weaknesses,
                    detected_goals,
                    written_feedback,
                    study_plan,
                    first_activity,
                    scores,
                    cefr_evidence,
                    recommendations,
                    raw_payload,
                    delivery_channel
                )
                VALUES (
                    :student_id,
                    :estimated_level,
                    :confidence_score,
                    CAST(:strengths AS jsonb),
                    CAST(:weaknesses AS jsonb),
                    CAST(:detected_goals AS jsonb),
                    :written_feedback,
                    CAST(:study_plan AS jsonb),
                    CAST(:first_activity AS jsonb),
                    CAST(:scores AS jsonb),
                    CAST(:cefr_evidence AS jsonb),
                    CAST(:recommendations AS jsonb),
                    CAST(:raw_payload AS jsonb),
                    :delivery_channel
                )
            ");
            $query->execute([
                'student_id' => $studentId,
                'estimated_level' => $level,
                'confidence_score' => $diagnostic['confidence_score'] ?? $diagnostic['confidence'] ?? null,
                'strengths' => json_encode($strengths, JSON_UNESCAPED_UNICODE),
                'weaknesses' => json_encode($weaknesses, JSON_UNESCAPED_UNICODE),
                'detected_goals' => json_encode($recommendations, JSON_UNESCAPED_UNICODE),
                'written_feedback' => $teacherMessage !== ''
                    ? $teacherMessage
                    : (string)($diagnostic['feedback'] ?? 'Diagnóstico concluído.'),
                'study_plan' => json_encode($studyPlan, JSON_UNESCAPED_UNICODE),
                'first_activity' => json_encode($firstActivity, JSON_UNESCAPED_UNICODE),
                'scores' => json_encode($scores, JSON_UNESCAPED_UNICODE),
                'cefr_evidence' => json_encode($diagnostic['cefr_evidence'] ?? [], JSON_UNESCAPED_UNICODE),
                'recommendations' => json_encode($recommendations, JSON_UNESCAPED_UNICODE),
                'raw_payload' => json_encode($diagnostic, JSON_UNESCAPED_UNICODE),
                'delivery_channel' => $messageType === 'audio' ? 'voice' : 'text',
            ]);
        },
        $warnings
    );

    $levelMap = [
        'PRE-A1' => 'A1',
        'A1' => 'A2',
        'A2' => 'B1',
        'B1' => 'B2',
        'B2' => 'C1',
        'C1' => 'C2',
        'C2' => 'C2',
    ];
    $targetLevel = $levelMap[$level] ?? 'A1';

    diagnostic_optional_step(
        $pdo,
        'sp_study_plan',
        'plano de estudos não foi registrado',
        static function () use (
            $pdo,
            $studentId,
            $studyPlan,
            $targetLevel
        ): void {
            $pdo->prepare("
                UPDATE study_plans
                SET status = 'archived'
                WHERE student_id = :student_id
                  AND status = 'active'
            ")->execute(['student_id' => $studentId]);

            $query = $pdo->prepare("
                INSERT INTO study_plans (
                    student_id,
                    start_date,
                    end_date,
                    goal,
                    target_level,
                    status,
                    plan_data
                )
                VALUES (
                    :student_id,
                    CURRENT_DATE,
                    CURRENT_DATE + 28,
                    :goal,
                    :target_level,
                    'active',
                    CAST(:plan_data AS jsonb)
                )
            ");
            $query->execute([
                'student_id' => $studentId,
                'goal' => (string)($studyPlan['goal'] ?? 'Melhorar conversação em inglês'),
                'target_level' => $targetLevel,
                'plan_data' => json_encode($studyPlan, JSON_UNESCAPED_UNICODE),
            ]);
        },
        $warnings
    );

    $stage = 'completing_diagnostic_session';

    $query = $pdo->prepare("
        UPDATE sessions
        SET
            status = 'completed',
            ended_at = NOW(),
            level = :level,
            grammar_score = :grammar,
            vocabulary_score = :vocabulary,
            fluency_score = :fluency,
            comprehension_score = :comprehension
        WHERE id = :session_id
    ");
    $query->execute([
        'level' => $level,
        'grammar' => $grammar,
        'vocabulary' => $vocabulary,
        'fluency' => $fluency,
        'comprehension' => round(($listening + $reading) / 2, 2),
        'session_id' => $sessionId,
    ]);

    $pdo->commit();

    json_response([
        'success' => true,
        'complete' => true,
        'student_id' => $studentId,
        'session_id' => $sessionId,
        'official_level' => $level,
        'target_level' => $targetLevel,
        'warnings' => $warnings,
    ], 201);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        '[RS ENGLISH DIAGNOSTIC ERROR] '
        . $errorReference
        . ' | stage='
        . $stage
        . ' | '
        . get_class($exception)
        . ' | '
        . $exception->getMessage()
        . ' | '
        . $exception->getFile()
        . ':'
        . $exception->getLine()
    );

    $response = [
        'success' => false,
        'error' => 'Não foi possível salvar o diagnóstico.',
        'stage' => $stage,
        'error_reference' => $errorReference,
    ];

    $debugEnabled = filter_var(
        (string)env('APP_DEBUG', 'false'),
        FILTER_VALIDATE_BOOL
    );

    if ($debugEnabled) {
        $response['exception'] = get_class($exception);
        $response['details'] = $exception->getMessage();
        $response['code'] = (string)$exception->getCode();
        $response['line'] = $exception->getLine();
    }

    json_response($response, 500);
}
