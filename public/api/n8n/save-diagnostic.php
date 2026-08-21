<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/db.php';
require_once __DIR__ . '/../../../src/api.php';
require_once __DIR__ . '/../../../src/progress.php';
require_once __DIR__ . '/../../../src/learning.php';

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

$selfAssessment = isset($diagnostic['self_assessment_option'])
    ? (int)$diagnostic['self_assessment_option']
    : null;
if ($selfAssessment !== null && ($selfAssessment < 1 || $selfAssessment > 5)) {
    $selfAssessment = null;
}

$supportMode = strtolower(trim((string)($diagnostic['support_mode'] ?? '')));
$teachingMode = strtolower(trim((string)($diagnostic['teaching_mode'] ?? '')));
$preferredExplanationLanguage = trim((string)($diagnostic['preferred_explanation_language'] ?? ''));
$diagnosticConfidence = max(0, min(100, (float)($diagnostic['confidence'] ?? $diagnostic['confidence_score'] ?? 0)));

$modeDefaults = [
    1 => ['pt_first', 'foundations', 'pt-BR', 'portuguese', 'light'],
    2 => ['bilingual', 'guided', 'pt-BR', 'portuguese', 'light'],
    3 => ['bilingual', 'guided_conversation', 'pt-BR', 'adaptive', 'balanced'],
    4 => ['english_first', 'conversation', 'adaptive', 'adaptive', 'balanced'],
    5 => ['bilingual', 'guided_conversation', 'adaptive', 'adaptive', 'balanced'],
];
$defaultMode = $modeDefaults[$selfAssessment ?? 0] ?? ['pt_first', 'foundations', 'pt-BR', 'portuguese', 'balanced'];

if (!in_array($supportMode, ['pt_first', 'bilingual', 'english_first', 'english_only'], true)) {
    $supportMode = $defaultMode[0];
}
if (!in_array($teachingMode, ['foundations', 'guided', 'guided_conversation', 'conversation', 'immersion'], true)) {
    $teachingMode = $defaultMode[1];
}
if (!in_array($preferredExplanationLanguage, ['pt-BR', 'adaptive', 'en'], true)) {
    $preferredExplanationLanguage = $defaultMode[2];
}
if (!isset($diagnostic['language_support'])) {
    $languageSupport = $defaultMode[3];
}
$correctionMode = strtolower(trim((string)($diagnostic['correction_mode'] ?? $defaultMode[4])));
if (!in_array($correctionMode, ['light', 'balanced', 'intensive'], true)) {
    $correctionMode = $defaultMode[4];
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
                support_mode,
                teaching_mode,
                preferred_explanation_language,
                diagnostic_confidence,
                initial_self_assessment,
                pre_a1
            )
            VALUES (
                :student_id,
                'PRE-A1',
                'PRE-A1',
                'Aprender inglês',
                :correction_mode,
                'in_progress',
                0,
                NOW(),
                :language_support,
                :support_mode,
                :teaching_mode,
                :preferred_explanation_language,
                :diagnostic_confidence,
                CAST(:self_assessment AS integer),
                CAST(:pre_a1 AS boolean)
            )
        ");
        $query->execute([
            'student_id' => $studentId,
            'correction_mode' => $correctionMode,
            'language_support' => $languageSupport,
            'support_mode' => $supportMode,
            'teaching_mode' => $teachingMode,
            'preferred_explanation_language' => $preferredExplanationLanguage,
            'diagnostic_confidence' => $diagnosticConfidence,
            'self_assessment' => $selfAssessment,
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

    $diagnosticCorrectionsSaved = 0;
    if ($corrections !== []) {
        $diagnosticCorrectionsSaved = learning_sync_corrections(
            $pdo,
            (string)$studentId,
            $corrections,
            [
                'channel' => $messageType === 'audio' ? 'whatsapp_voice' : 'whatsapp',
                'session_id' => (string)$sessionId,
                'event_prefix' => learning_event_key('diagnostic-correction', [
                    (string)$sessionId,
                    (string)$nextStep,
                ]),
            ]
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
                support_mode = :support_mode,
                teaching_mode = :teaching_mode,
                preferred_explanation_language = :preferred_explanation_language,
                diagnostic_confidence = :diagnostic_confidence,
                initial_self_assessment = COALESCE(CAST(:self_assessment AS integer), initial_self_assessment),
                correction_mode = :correction_mode,
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
            'support_mode' => $supportMode,
            'teaching_mode' => $teachingMode,
            'preferred_explanation_language' => $preferredExplanationLanguage,
            'diagnostic_confidence' => $diagnosticConfidence,
            'self_assessment' => $selfAssessment,
            'correction_mode' => $correctionMode,
            'pre_a1' => $preA1DatabaseValue,
            'student_id' => $studentId,
        ]);

        diagnostic_optional_step(
            $pdo,
            'sp_diagnostic_preferences',
            'preferências adaptativas não foram sincronizadas',
            static function () use ($pdo, $studentId, $correctionMode, $languageSupport): void {
                $pdo->prepare("
                    INSERT INTO student_preferences(student_id, correction_mode, explanations_language)
                    VALUES(:student_id, :correction_mode, :explanations_language)
                    ON CONFLICT(student_id) DO UPDATE SET
                        correction_mode = EXCLUDED.correction_mode,
                        explanations_language = EXCLUDED.explanations_language,
                        updated_at = NOW()
                ")->execute([
                    'student_id' => $studentId,
                    'correction_mode' => $correctionMode,
                    'explanations_language' => $languageSupport,
                ]);
            },
            $warnings
        );

        $partialSkills = learning_record_evaluation(
            $pdo,
            (string)$studentId,
            [
                'scores' => $scores,
                'confidence_score' => $diagnosticConfidence,
            ],
            [
                'source' => 'diagnostic_step',
                'event_prefix' => learning_event_key('diagnostic-step-skill', [
                    (string)$sessionId,
                    (string)$nextStep,
                ]),
                'session_id' => (string)$sessionId,
                'message_type' => $messageType,
                'weight' => 1.5,
                'confidence' => $diagnosticConfidence,
                'evidence_text' => $studentMessage,
                'evidence_data' => [
                    'step' => $nextStep,
                    'estimated_level' => $level,
                ],
            ]
        );

        learning_record_event(
            $pdo,
            (string)$studentId,
            learning_event_key('diagnostic-step', [(string)$sessionId, (string)$nextStep]),
            'diagnostic_step',
            $messageType === 'audio' ? 'whatsapp_voice' : 'whatsapp',
            (string)$sessionId,
            null,
            max(0, (int)round((float)($data['audio_duration_seconds'] ?? 0))),
            $partialSkills !== [] ? round(array_sum($partialSkills) / count($partialSkills), 2) : null,
            2,
            [
                'step' => $nextStep,
                'estimated_level' => $level,
                'support_mode' => $supportMode,
                'teaching_mode' => $teachingMode,
                'skills_recorded' => array_keys($partialSkills),
                'corrections_saved' => $diagnosticCorrectionsSaved,
            ]
        );

        $pdo->commit();

        json_response([
            'success' => true,
            'complete' => false,
            'student_id' => $studentId,
            'session_id' => $sessionId,
            'next_step' => $nextStep,
            'estimated_level' => $level,
            'support_mode' => $supportMode,
            'teaching_mode' => $teachingMode,
            'self_assessment_option' => $selfAssessment,
            'telemetry' => [
                'skills_recorded' => array_keys($partialSkills),
                'corrections_saved' => $diagnosticCorrectionsSaved,
            ],
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
            support_mode = :support_mode,
            teaching_mode = :teaching_mode,
            preferred_explanation_language = :preferred_explanation_language,
            diagnostic_confidence = :diagnostic_confidence,
            initial_self_assessment = COALESCE(CAST(:self_assessment AS integer), initial_self_assessment),
            correction_mode = :correction_mode,
            onboarding_completed_at = COALESCE(onboarding_completed_at, NOW()),
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
        'support_mode' => $supportMode,
        'teaching_mode' => $teachingMode,
        'preferred_explanation_language' => $preferredExplanationLanguage,
        'diagnostic_confidence' => $diagnosticConfidence,
        'self_assessment' => $selfAssessment,
        'correction_mode' => $correctionMode,
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

    diagnostic_optional_step(
        $pdo,
        'sp_completed_preferences',
        'preferências finais não foram sincronizadas',
        static function () use ($pdo, $studentId, $correctionMode, $languageSupport): void {
            $pdo->prepare("
                INSERT INTO student_preferences(student_id, correction_mode, explanations_language)
                VALUES(:student_id, :correction_mode, :explanations_language)
                ON CONFLICT(student_id) DO UPDATE SET
                    correction_mode = EXCLUDED.correction_mode,
                    explanations_language = EXCLUDED.explanations_language,
                    updated_at = NOW()
            ")->execute([
                'student_id' => $studentId,
                'correction_mode' => $correctionMode,
                'explanations_language' => $languageSupport,
            ]);
        },
        $warnings
    );

    $finalSkillPayload = [
        'grammar_score' => $grammar,
        'vocabulary_score' => $vocabulary,
        'speaking_score' => $speaking,
        'listening_score' => $listening,
        'reading_score' => $reading,
        'writing_score' => $writing,
        'fluency_score' => $fluency,
        'pronunciation_score' => $pronunciation,
        'confidence_score' => $diagnosticConfidence,
    ];

    $finalSkills = learning_record_evaluation(
        $pdo,
        (string)$studentId,
        $finalSkillPayload,
        [
            'source' => 'diagnostic_final',
            'event_prefix' => learning_event_key('diagnostic-final-skill', [(string)$sessionId]),
            'session_id' => (string)$sessionId,
            'message_type' => $messageType,
            'weight' => 5.0,
            'confidence' => $diagnosticConfidence,
            'evidence_text' => $studentMessage,
            'evidence_data' => [
                'official_level' => $level,
                'support_mode' => $supportMode,
                'teaching_mode' => $teachingMode,
                'cefr_evidence' => $diagnostic['cefr_evidence'] ?? [],
            ],
        ]
    );

    learning_record_event(
        $pdo,
        (string)$studentId,
        learning_event_key('diagnostic-completed', [(string)$sessionId]),
        'diagnostic_completed',
        $messageType === 'audio' ? 'whatsapp_voice' : 'whatsapp',
        (string)$sessionId,
        null,
        max(0, (int)round((float)($data['audio_duration_seconds'] ?? 0))),
        $total,
        25,
        [
            'official_level' => $level,
            'target_level' => $targetLevel,
            'confidence' => $diagnosticConfidence,
            'skills_recorded' => array_keys($finalSkills),
            'corrections_saved' => $diagnosticCorrectionsSaved,
        ]
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
    progress_refresh_after_event((string)$studentId);

    json_response([
        'success' => true,
        'complete' => true,
        'student_id' => $studentId,
        'session_id' => $sessionId,
        'official_level' => $level,
        'target_level' => $targetLevel,
        'support_mode' => $supportMode,
        'teaching_mode' => $teachingMode,
        'self_assessment_option' => $selfAssessment,
        'telemetry' => [
            'skills_recorded' => array_keys($finalSkills),
            'corrections_saved' => $diagnosticCorrectionsSaved,
            'event_score' => $total,
        ],
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
