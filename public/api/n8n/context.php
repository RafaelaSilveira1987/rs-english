<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/db.php';
require_once __DIR__ . '/../../../src/api.php';
require_once __DIR__ . '/../../../src/conversation.php';

require_n8n_key();

$phone = normalize_phone($_GET['phone'] ?? '');
$name = trim((string)($_GET['name'] ?? 'Aluno'));

if ($phone === '') {
    json_response([
        'ok' => false,
        'error' => 'phone é obrigatório',
    ], 422);
}

if ($name === '') {
    $name = 'Aluno';
}

$pdo = db();

/**
 * Busca o aluno e o perfil pelo telefone normalizado.
 */
$findStudent = static function (PDO $pdo, string $phone): array|false {
    $query = $pdo->prepare("
        SELECT
            s.id,
            s.name,
            s.phone,
            s.email,
            COALESCE(sp.overall_level, 'PRE-A1') AS overall_level,
            COALESCE(sp.estimated_level, 'PRE-A1') AS estimated_level,
            COALESCE(sp.goal, 'Aprender inglês') AS goal,
            COALESCE(sp.correction_mode, 'balanced') AS correction_mode,
            COALESCE(sp.preferred_language_support, 'portuguese') AS preferred_language_support,
            COALESCE(sp.diagnostic_status, 'pending') AS diagnostic_status,
            COALESCE(sp.diagnostic_step, 0) AS diagnostic_step,
            COALESCE(sp.grammar_score, 0) AS grammar_score,
            COALESCE(sp.vocabulary_score, 0) AS vocabulary_score,
            COALESCE(sp.speaking_score, 0) AS speaking_score,
            COALESCE(sp.listening_score, 0) AS listening_score,
            COALESCE(sp.reading_score, 0) AS reading_score,
            COALESCE(sp.writing_score, 0) AS writing_score,
            COALESCE(sp.fluency_score, 0) AS fluency_score,
            COALESCE(sp.pronunciation_score, 0) AS pronunciation_score,
            COALESCE(sp.xp, 0) AS xp,
            COALESCE(sp.streak_days, 0) AS streak_days,
            COALESCE(sp.pre_a1, TRUE) AS pre_a1,
            sp.initial_self_assessment,
            sp.last_study_at
        FROM students s
        LEFT JOIN student_profiles sp
            ON sp.student_id = s.id
        WHERE regexp_replace(COALESCE(s.phone, ''), '[^0-9]', '', 'g') = :phone
        LIMIT 1
    ");

    $query->execute(['phone' => $phone]);

    return $query->fetch(PDO::FETCH_ASSOC);
};

$student = $findStudent($pdo, $phone);

/*
|--------------------------------------------------------------------------
| Cadastro automático do primeiro contato
|--------------------------------------------------------------------------
*/
if (!$student) {
    try {
        $pdo->beginTransaction();

        $createStudent = $pdo->prepare("
            INSERT INTO students (name, phone)
            VALUES (:name, :phone)
            ON CONFLICT DO NOTHING
            RETURNING id
        ");
        $createStudent->execute([
            'name' => $name,
            'phone' => $phone,
        ]);

        $studentId = $createStudent->fetchColumn();

        if (!$studentId) {
            $lookup = $pdo->prepare("
                SELECT id
                FROM students
                WHERE regexp_replace(COALESCE(phone, ''), '[^0-9]', '', 'g') = :phone
                LIMIT 1
            ");
            $lookup->execute(['phone' => $phone]);
            $studentId = $lookup->fetchColumn();
        }

        if (!$studentId) {
            throw new RuntimeException('Não foi possível obter o UUID do aluno.');
        }

        $createProfile = $pdo->prepare("
            INSERT INTO student_profiles (
                student_id,
                overall_level,
                estimated_level,
                diagnostic_status,
                diagnostic_step,
                diagnostic_started_at,
                goal,
                correction_mode,
                preferred_language_support,
                pre_a1
            )
            VALUES (
                :student_id,
                'PRE-A1',
                'PRE-A1',
                'pending',
                0,
                NULL,
                'Aprender inglês',
                'balanced',
                'portuguese',
                TRUE
            )
            ON CONFLICT (student_id) DO NOTHING
        ");
        $createProfile->execute(['student_id' => $studentId]);

        $pdo->commit();

        $student = $findStudent($pdo, $phone);

        if (!$student) {
            throw new RuntimeException('O aluno foi criado, mas não pôde ser consultado.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            '[RS ENGLISH CONTEXT] '
            . get_class($exception)
            . ': '
            . $exception->getMessage()
        );

        $response = [
            'ok' => false,
            'error' => 'Não foi possível criar o aluno.',
        ];

        if ((string)env('APP_ENV', 'production') !== 'production') {
            $response['details'] = $exception->getMessage();
        }

        json_response($response, 500);
    }
}

/*
|--------------------------------------------------------------------------
| Memória de aprendizagem
|--------------------------------------------------------------------------
*/
$query = $pdo->prepare("
    SELECT
        id,
        category,
        topic,
        canonical_key,
        original_text,
        corrected_text,
        explanation,
        severity,
        occurrences,
        mastery_score,
        next_review_at,
        last_review_at
    FROM student_errors
    WHERE student_id = :student_id
      AND status = 'learning'
    ORDER BY occurrences DESC, mastery_score ASC, created_at DESC
    LIMIT 12
");
$query->execute(['student_id' => $student['id']]);
$weaknesses = $query->fetchAll(PDO::FETCH_ASSOC);

$query = $pdo->prepare("
    SELECT
        id,
        category,
        topic,
        canonical_key,
        original_text,
        corrected_text,
        explanation,
        occurrences,
        mastery_score,
        next_review_at
    FROM student_errors
    WHERE student_id = :student_id
      AND status = 'learning'
      AND (next_review_at IS NULL OR next_review_at <= NOW())
    ORDER BY occurrences DESC, mastery_score ASC
    LIMIT 8
");
$query->execute(['student_id' => $student['id']]);
$errorDue = $query->fetchAll(PDO::FETCH_ASSOC);

$query = $pdo->prepare("
    SELECT
        sv.id AS student_vocabulary_id,
        v.id AS vocabulary_id,
        v.word,
        v.translation,
        v.definition_en,
        v.example,
        v.level,
        v.category,
        sv.status,
        sv.mastery_score,
        sv.repetitions,
        sv.correct_answers,
        sv.incorrect_answers,
        sv.next_review_at
    FROM student_vocabulary sv
    INNER JOIN vocabulary v
        ON v.id = sv.vocabulary_id
    WHERE sv.student_id = :student_id
      AND sv.status IN ('learning', 'review')
      AND (sv.next_review_at IS NULL OR sv.next_review_at <= NOW())
    ORDER BY sv.next_review_at NULLS FIRST, sv.mastery_score ASC
    LIMIT 10
");
$query->execute(['student_id' => $student['id']]);
$vocabularyDue = $query->fetchAll(PDO::FETCH_ASSOC);

$query = $pdo->prepare("
    SELECT role, content, transcription, message_type, created_at
    FROM messages
    WHERE student_id = :student_id
    ORDER BY created_at DESC
    LIMIT 14
");
$query->execute(['student_id' => $student['id']]);
$recentMessages = array_reverse($query->fetchAll(PDO::FETCH_ASSOC));

$query = $pdo->prepare("
    SELECT id, goal, target_level, plan_data, created_at, start_date, end_date
    FROM study_plans
    WHERE student_id = :student_id
      AND status = 'active'
    ORDER BY created_at DESC
    LIMIT 1
");
$query->execute(['student_id' => $student['id']]);
$activePlan = $query->fetch(PDO::FETCH_ASSOC) ?: null;

$query = $pdo->prepare("
    SELECT
        COUNT(*) FILTER (WHERE status = 'mastered') AS mastered,
        COUNT(*) FILTER (WHERE status IN ('learning', 'review')) AS learning,
        COUNT(*) FILTER (
            WHERE status IN ('learning', 'review')
              AND (next_review_at IS NULL OR next_review_at <= NOW())
        ) AS due
    FROM student_vocabulary
    WHERE student_id = :student_id
");
$query->execute(['student_id' => $student['id']]);
$vocabularyStats = $query->fetch(PDO::FETCH_ASSOC);

$query = $pdo->prepare("
    SELECT
        id,
        channel,
        mode,
        topic,
        COALESCE(conversation_topic, topic, 'daily_life') AS conversation_topic,
        COALESCE(conversation_style, 'guided') AS conversation_style,
        COALESCE(turn_count, 0) AS turn_count,
        COALESCE(max_turns, 10) AS max_turns,
        created_at,
        last_student_message_at,
        last_teacher_message_at
    FROM sessions
    WHERE student_id = :student_id
      AND status = 'active'
      AND mode = 'conversation'
      AND created_at >= NOW() - INTERVAL '12 hours'
    ORDER BY created_at DESC
    LIMIT 1
");
$query->execute(['student_id' => $student['id']]);
$conversationSession = $query->fetch(PDO::FETCH_ASSOC) ?: null;

$query = $pdo->prepare("
    SELECT
        focus_mode,
        correction_mode,
        preferred_topics,
        conversation_topic,
        conversation_style,
        conversation_max_turns
    FROM student_preferences
    WHERE student_id = :student_id
    LIMIT 1
");
$query->execute(['student_id' => $student['id']]);
$studentPreferences = $query->fetch(PDO::FETCH_ASSOC) ?: [
    'focus_mode' => 'conversation',
    'correction_mode' => $student['correction_mode'] ?? 'balanced',
    'preferred_topics' => [],
    'conversation_topic' => 'daily_life',
    'conversation_style' => 'guided',
    'conversation_max_turns' => 10,
];

if (is_string($studentPreferences['preferred_topics'] ?? null)) {
    $decodedTopics = json_decode($studentPreferences['preferred_topics'], true);
    $studentPreferences['preferred_topics'] = is_array($decodedTopics)
        ? $decodedTopics
        : [];
}

$conversation = null;

if ($conversationSession) {
    $turnCount = (int)$conversationSession['turn_count'];
    $maxTurns = (int)$conversationSession['max_turns'];

    $conversation = [
        'session_id' => $conversationSession['id'],
        'channel' => $conversationSession['channel'],
        'mode' => 'conversation',
        'topic' => $conversationSession['conversation_topic'],
        'style' => $conversationSession['conversation_style'],
        'turn_count' => $turnCount,
        'max_turns' => $maxTurns,
        'remaining_turns' => max(0, $maxTurns - $turnCount),
        'should_wrap_up' => $turnCount >= max(1, $maxTurns - 2),
        'should_finish' => $turnCount >= $maxTurns,
        'created_at' => $conversationSession['created_at'],
    ];
}

json_response([
    'ok' => true,
    'found' => true,
    'student_id' => $student['id'],
    'phone' => $phone,
    'student' => $student,
    'diagnostic_status' => $student['diagnostic_status'] ?? 'pending',
    'diagnostic_step' => (int)($student['diagnostic_step'] ?? 0),
    'weaknesses' => $weaknesses,
    'reviews_due' => [
        'vocabulary' => $vocabularyDue,
        'errors' => $errorDue,
        'total' => count($vocabularyDue) + count($errorDue),
    ],
    'vocabulary_stats' => $vocabularyStats,
    'recent_messages' => $recentMessages,
    'active_plan' => $activePlan,
    'student_preferences' => $studentPreferences,
    'conversation' => $conversation,
]);
