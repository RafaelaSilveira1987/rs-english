<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/db.php';
require_once __DIR__ . '/../../../src/api.php';
require_once __DIR__ . '/../../../src/conversation.php';
require_once __DIR__ . '/../../../src/progress.php';
require_once __DIR__ . '/../../../src/learning.php';

require_n8n_key();

$data = json_input();

$phone = normalize_phone($data['phone'] ?? '');
$name = trim((string)($data['student_name'] ?? 'Aluno'));
$studentMessage = trim((string)($data['student_message'] ?? ''));
$teacherMessage = portal_clean_text($data['teacher_message'] ?? '');
$messageType = trim((string)($data['message_type'] ?? 'text'));
$eventChannel = trim((string)($data['channel'] ?? 'whatsapp')) ?: 'whatsapp';
$mode = conversation_mode(trim((string)($data['mode'] ?? 'conversation')));
$topic = conversation_topic((string)($data['topic'] ?? ''));
$evaluation = is_array($data['evaluation'] ?? null)
    ? $data['evaluation']
    : [];

$conversationInput = is_array($data['conversation'] ?? null)
    ? $data['conversation']
    : [];

$conversationStyle = conversation_style(
    (string)($conversationInput['style'] ?? $data['conversation_style'] ?? 'guided')
);

$maxTurns = conversation_max_turns(
    $conversationInput['max_turns'] ?? $data['max_turns'] ?? 10
);

$sessionEnd = !empty($data['session_end'])
    || !empty($evaluation['session_complete']);

$sessionSummary = trim((string)(
    $data['session_summary']
    ?? $evaluation['session_summary']
    ?? ''
));

$summaryData = is_array($data['summary_data'] ?? null)
    ? $data['summary_data']
    : (is_array($evaluation['summary_data'] ?? null)
        ? $evaluation['summary_data']
        : []);

if ($phone === '' || $studentMessage === '') {
    json_response([
        'success' => false,
        'error' => 'phone e student_message são obrigatórios',
    ], 422);
}

if (!in_array($messageType, ['text', 'audio'], true)) {
    $messageType = 'text';
}

function clamp_score_v104(mixed $value): float
{
    return max(0, min(100, (float)$value));
}

function canonical_key_v104(array $error): string
{
    $key = strtolower(trim((string)(
        $error['topic']
        ?? $error['category']
        ?? 'other'
    )));

    $key = preg_replace('/[^a-z0-9_]+/i', '_', $key);

    return trim((string)$key, '_') ?: 'other';
}

function normalize_word_v104(string $word): string
{
    return preg_replace(
        '/\s+/',
        ' ',
        trim(mb_strtolower($word))
    ) ?: '';
}

$pdo = db();

try {
    $pdo->beginTransaction();

    $query = $pdo->prepare("
        SELECT id
        FROM students
        WHERE regexp_replace(
            COALESCE(phone, ''),
            '[^0-9]',
            '',
            'g'
        ) = :phone
        LIMIT 1
    ");
    $query->execute(['phone' => $phone]);
    $studentId = $query->fetchColumn();

    if (!$studentId) {
        $query = $pdo->prepare("
            INSERT INTO students(name, phone)
            VALUES(:name, :phone)
            RETURNING id
        ");
        $query->execute([
            'name' => $name !== '' ? $name : 'Aluno',
            'phone' => $phone,
        ]);
        $studentId = $query->fetchColumn();

        $pdo->prepare("
            INSERT INTO student_profiles(
                student_id,
                overall_level,
                estimated_level,
                goal,
                correction_mode,
                diagnostic_status,
                diagnostic_step,
                preferred_language_support,
                pre_a1
            )
            VALUES(
                :student_id,
                'PRE-A1',
                'PRE-A1',
                'Aprender inglês',
                'balanced',
                'pending',
                0,
                'portuguese',
                TRUE
            )
        ")->execute(['student_id' => $studentId]);
    }

    $session = null;

    if ($mode === 'conversation') {
        $query = $pdo->prepare("
            SELECT
                id,
                COALESCE(turn_count, 0) AS turn_count,
                COALESCE(max_turns, 10) AS max_turns,
                COALESCE(conversation_topic, topic, 'daily_life') AS conversation_topic,
                COALESCE(conversation_style, 'guided') AS conversation_style
            FROM sessions
            WHERE student_id = :student_id
              AND status = 'active'
              AND mode = 'conversation'
              AND created_at >= NOW() - INTERVAL '12 hours'
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $query->execute(['student_id' => $studentId]);
        $session = $query->fetch(PDO::FETCH_ASSOC) ?: null;
    } else {
        $query = $pdo->prepare("
            SELECT id
            FROM sessions
            WHERE student_id = :student_id
              AND status = 'active'
              AND mode = :mode
              AND created_at >= NOW() - INTERVAL '4 hours'
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $query->execute([
            'student_id' => $studentId,
            'mode' => $mode,
        ]);
        $sessionId = $query->fetchColumn();

        if ($sessionId) {
            $session = [
                'id' => $sessionId,
                'turn_count' => 0,
                'max_turns' => $maxTurns,
                'conversation_topic' => $topic,
                'conversation_style' => $conversationStyle,
            ];
        }
    }

    if (!$session) {
        $query = $pdo->prepare("
            INSERT INTO sessions(
                student_id,
                channel,
                mode,
                topic,
                status,
                turn_count,
                max_turns,
                conversation_topic,
                conversation_style
            )
            VALUES(
                :student_id,
                :channel,
                :mode,
                :topic,
                'active',
                0,
                :max_turns,
                :conversation_topic,
                :conversation_style
            )
            RETURNING id
        ");
        $query->execute([
            'student_id' => $studentId,
            'channel' => str_starts_with($eventChannel, 'web')
                ? $eventChannel
                : 'whatsapp',
            'mode' => $mode,
            'topic' => $topic !== '' ? $topic : null,
            'max_turns' => $maxTurns,
            'conversation_topic' => $mode === 'conversation' ? $topic : null,
            'conversation_style' => $conversationStyle,
        ]);

        $session = [
            'id' => $query->fetchColumn(),
            'turn_count' => 0,
            'max_turns' => $maxTurns,
            'conversation_topic' => $topic,
            'conversation_style' => $conversationStyle,
        ];
    }

    $sessionId = (string)$session['id'];
    $audioDurationSeconds = max(0, (int)round((float)($data['audio_duration_seconds'] ?? 0)));
    $interactionDurationSeconds = learning_interaction_duration(
        $pdo,
        (string)$studentId,
        $sessionId,
        $eventChannel,
        $messageType,
        $audioDurationSeconds,
        $data['interaction_duration_seconds'] ?? null
    );
    $currentTurnCount = (int)($session['turn_count'] ?? 0);
    $sessionMaxTurns = conversation_max_turns(
        $session['max_turns'] ?? $maxTurns
    );

    $query = $pdo->prepare("
        INSERT INTO messages(
            session_id,
            student_id,
            role,
            message_type,
            content,
            transcription
        )
        VALUES(
            :session_id,
            :student_id,
            'student',
            :message_type,
            :content,
            :transcription
        )
        RETURNING id
    ");
    $query->execute([
        'session_id' => $sessionId,
        'student_id' => $studentId,
        'message_type' => $messageType,
        'content' => $studentMessage,
        'transcription' => $messageType === 'audio'
            ? $studentMessage
            : null,
    ]);
    $messageId = $query->fetchColumn();

    if ($teacherMessage !== '') {
        $pdo->prepare("
            INSERT INTO messages(
                session_id,
                student_id,
                role,
                message_type,
                content
            )
            VALUES(
                :session_id,
                :student_id,
                'teacher',
                'text',
                :content
            )
        ")->execute([
            'session_id' => $sessionId,
            'student_id' => $studentId,
            'content' => $teacherMessage,
        ]);
    }

    $newTurnCount = $currentTurnCount;

    if ($mode === 'conversation') {
        $newTurnCount++;

        $pdo->prepare("
            UPDATE sessions
            SET
                turn_count = :turn_count,
                max_turns = :max_turns,
                conversation_topic = COALESCE(
                    NULLIF(:conversation_topic, ''),
                    conversation_topic,
                    topic,
                    'daily_life'
                ),
                conversation_style = :conversation_style,
                last_student_message_at = NOW(),
                last_teacher_message_at = CASE
                    WHEN CAST(:has_teacher_message AS INTEGER) = 1 THEN NOW()
                    ELSE last_teacher_message_at
                END
            WHERE id = :session_id
        ")->execute([
            'turn_count' => $newTurnCount,
            'max_turns' => $sessionMaxTurns,
            'conversation_topic' => $topic,
            'conversation_style' => $conversationStyle,
            'has_teacher_message' => $teacherMessage !== '' ? 1 : 0,
            'session_id' => $sessionId,
        ]);
    }

    $correctionPayload = $evaluation['corrections'] ?? $evaluation['errors'] ?? [];
    $correctionsSaved = 0;
    if (is_array($correctionPayload) && $correctionPayload !== []) {
        $correctionsSaved = learning_sync_corrections(
            $pdo,
            (string)$studentId,
            $correctionPayload,
            [
                'channel' => (string)($data['channel'] ?? 'whatsapp'),
                'session_id' => (string)$sessionId,
                'message_id' => (string)$messageId,
                'event_prefix' => learning_event_key('interaction-correction', [(string)$messageId]),
            ]
        );
    }

    $vocabularySaved = learning_sync_vocabulary(
        $pdo,
        (string)$studentId,
        learning_vocabulary_items($evaluation),
        [
            'source' => $mode === 'assessment' ? 'diagnostic' : 'conversation',
            'level' => (string)($evaluation['estimated_level'] ?? $data['level'] ?? ''),
            'source_context' => [
                'session_id' => $sessionId,
                'message_id' => (string)$messageId,
                'channel' => $eventChannel,
                'topic' => $topic,
            ],
        ]
    );

    if ($messageType === 'audio' && !str_starts_with($eventChannel, 'web')) {
        $pdo->prepare(<<<'SQL'
            INSERT INTO voice_conversations(
                student_id, channel, student_audio_duration_seconds,
                student_transcription, teacher_text, session_id,
                status, source_message_id
            ) VALUES(
                :student_id, :channel, :duration,
                :transcription, NULLIF(:teacher_text, ''), :session_id,
                'completed', :source_message_id
            )
            ON CONFLICT(source_message_id) WHERE source_message_id IS NOT NULL
            DO UPDATE SET
                student_audio_duration_seconds = COALESCE(EXCLUDED.student_audio_duration_seconds, voice_conversations.student_audio_duration_seconds),
                student_transcription = COALESCE(NULLIF(EXCLUDED.student_transcription, ''), voice_conversations.student_transcription),
                teacher_text = COALESCE(NULLIF(EXCLUDED.teacher_text, ''), voice_conversations.teacher_text),
                session_id = COALESCE(EXCLUDED.session_id, voice_conversations.session_id),
                status = 'completed'
        SQL)->execute([
            'student_id' => $studentId,
            'channel' => $eventChannel === 'whatsapp' ? 'whatsapp_voice' : $eventChannel,
            'duration' => $audioDurationSeconds > 0 ? $audioDurationSeconds : null,
            'transcription' => $studentMessage,
            'teacher_text' => $teacherMessage,
            'session_id' => $sessionId,
            'source_message_id' => $messageId,
        ]);
    }

    foreach (($evaluation['skills'] ?? []) as $skill) {
        if (!is_array($skill) || empty($skill['code'])) {
            continue;
        }

        $query = $pdo->prepare("
            SELECT id
            FROM skills
            WHERE code = :code
            LIMIT 1
        ");
        $query->execute(['code' => $skill['code']]);
        $skillId = $query->fetchColumn();

        if (!$skillId) {
            continue;
        }

        $score = clamp_score_v104($skill['score'] ?? 0);
        $success = !empty($skill['success']) ? 1 : 0;

        $pdo->prepare("
            INSERT INTO student_skills(
                student_id,
                skill_id,
                score,
                attempts,
                successes,
                last_practiced_at,
                updated_at
            )
            VALUES(
                :student_id,
                :skill_id,
                :score,
                1,
                :success,
                NOW(),
                NOW()
            )
            ON CONFLICT(student_id, skill_id)
            DO UPDATE SET
                score = ROUND(
                    ((student_skills.score * 3) + EXCLUDED.score) / 4,
                    2
                ),
                attempts = student_skills.attempts + 1,
                successes = student_skills.successes + EXCLUDED.successes,
                last_practiced_at = NOW(),
                updated_at = NOW()
        ")->execute([
            'student_id' => $studentId,
            'skill_id' => $skillId,
            'score' => $score,
            'success' => $success,
        ]);
    }

    $grammar = array_key_exists('grammar_score', $evaluation)
        ? clamp_score_v104($evaluation['grammar_score'])
        : null;

    $vocabulary = array_key_exists('vocabulary_score', $evaluation)
        ? clamp_score_v104($evaluation['vocabulary_score'])
        : null;

    $fluency = array_key_exists('fluency_score', $evaluation)
        ? clamp_score_v104($evaluation['fluency_score'])
        : null;

    $comprehension = array_key_exists('comprehension_score', $evaluation)
        ? clamp_score_v104($evaluation['comprehension_score'])
        : null;

    $pdo->prepare("
        UPDATE sessions
        SET
            grammar_score = COALESCE(:grammar, grammar_score),
            vocabulary_score = COALESCE(:vocabulary, vocabulary_score),
            fluency_score = COALESCE(:fluency, fluency_score),
            comprehension_score = COALESCE(
                :comprehension,
                comprehension_score
            )
        WHERE id = :session_id
    ")->execute([
        'grammar' => $grammar,
        'vocabulary' => $vocabulary,
        'fluency' => $fluency,
        'comprehension' => $comprehension,
        'session_id' => $sessionId,
    ]);

    $pdo->prepare("
        UPDATE student_profiles
        SET
            grammar_score = COALESCE(:grammar, grammar_score),
            vocabulary_score = COALESCE(:vocabulary, vocabulary_score),
            fluency_score = COALESCE(:fluency, fluency_score),
            last_study_at = NOW(),
            xp = xp + :xp,
            updated_at = NOW()
        WHERE student_id = :student_id
    ")->execute([
        'grammar' => $grammar,
        'vocabulary' => $vocabulary,
        'fluency' => $fluency,
        'xp' => $mode === 'review' ? 8 : 5,
        'student_id' => $studentId,
    ]);

    $recordedSkills = learning_record_evaluation(
        $pdo,
        (string)$studentId,
        $evaluation,
        [
            'source' => $mode === 'assessment' ? 'diagnostic_interaction' : 'teacher_interaction',
            'event_prefix' => learning_event_key('interaction-skill', [(string)$messageId]),
            'session_id' => (string)$sessionId,
            'source_id' => (string)$messageId,
            'message_type' => $messageType,
            'weight' => $mode === 'assessment' ? 1.5 : 1.0,
            'confidence' => $evaluation['confidence_score'] ?? $evaluation['confidence'] ?? null,
            'evidence_text' => $studentMessage,
            'evidence_data' => [
                'channel' => (string)($data['channel'] ?? 'whatsapp'),
                'mode' => $mode,
                'topic' => $topic,
            ],
        ]
    );

    $eventScore = $recordedSkills !== []
        ? round(array_sum($recordedSkills) / count($recordedSkills), 2)
        : null;
    $eventXp = $mode === 'review' ? 8 : 5;

    learning_record_event(
        $pdo,
        (string)$studentId,
        learning_event_key('interaction', [(string)$messageId]),
        $mode === 'assessment' ? 'diagnostic_interaction' : 'conversation_turn',
        $eventChannel,
        (string)$sessionId,
        (string)$messageId,
        $interactionDurationSeconds,
        $eventScore,
        $eventXp,
        [
            'message_type' => $messageType,
            'mode' => $mode,
            'topic' => $topic,
            'teacher_replied' => $teacherMessage !== '',
            'skills_recorded' => array_keys($recordedSkills),
            'corrections_saved' => $correctionsSaved,
            'vocabulary_count' => count($vocabularySaved),
            'duration_method' => $messageType === 'audio' ? 'audio_length' : (str_starts_with($eventChannel, 'web') ? 'web_active_time' : 'whatsapp_session_interval'),
        ]
    );

    $shouldFinish = $mode === 'conversation'
        && ($sessionEnd || $newTurnCount >= $sessionMaxTurns);

    if ($shouldFinish) {
        $completedReason = $sessionEnd
            ? 'teacher_finished'
            : 'max_turns_reached';

        $pdo->prepare("
            UPDATE sessions
            SET
                status = 'completed',
                ended_at = NOW(),
                completed_reason = :completed_reason,
                conversation_summary = NULLIF(:summary, ''),
                summary_data = CAST(:summary_data AS jsonb)
            WHERE id = :session_id
        ")->execute([
            'completed_reason' => $completedReason,
            'summary' => $sessionSummary,
            'summary_data' => json_encode(
                $summaryData,
                JSON_UNESCAPED_UNICODE
            ),
            'session_id' => $sessionId,
        ]);
    }

    $pdo->commit();
    progress_refresh_after_event((string)$studentId);

    json_response([
        'success' => true,
        'student_id' => $studentId,
        'session_id' => $sessionId,
        'mode' => $mode,
        'telemetry' => [
            'skills_recorded' => array_keys($recordedSkills),
            'corrections_saved' => $correctionsSaved,
            'vocabulary_saved' => count($vocabularySaved),
            'duration_seconds' => $interactionDurationSeconds,
            'event_score' => $eventScore,
        ],
        'conversation' => $mode === 'conversation'
            ? [
                'topic' => $topic,
                'style' => $conversationStyle,
                'turn_count' => $newTurnCount,
                'max_turns' => $sessionMaxTurns,
                'remaining_turns' => max(
                    0,
                    $sessionMaxTurns - $newTurnCount
                ),
                'should_wrap_up' => $newTurnCount >= max(
                    1,
                    $sessionMaxTurns - 2
                ),
                'completed' => $shouldFinish,
            ]
            : null,
    ], 201);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        '[SAVE INTERACTION] '
        . get_class($exception)
        . ': '
        . $exception->getMessage()
    );

    $response = [
        'success' => false,
        'error' => 'Não foi possível salvar a interação.',
    ];

    if ((string)env('APP_ENV', 'production') !== 'production') {
        $response['details'] = $exception->getMessage();
    }

    json_response($response, 500);
}
