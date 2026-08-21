<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Camada única de telemetria pedagógica da RS English.
 *
 * Todos os canais (WhatsApp, portal, áudio, diagnóstico e atividades)
 * devem registrar eventos e evidências por meio destas funções. O painel
 * do aluno e o administrativo passam a ler a mesma fonte de verdade.
 */

function learning_clamp(mixed $value, float $minimum = 0.0, float $maximum = 100.0): float
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return $minimum;
    }

    return max($minimum, min($maximum, (float)$value));
}

function learning_skill_codes(): array
{
    return [
        'grammar',
        'vocabulary',
        'speaking',
        'listening',
        'reading',
        'writing',
        'fluency',
        'pronunciation',
    ];
}

function learning_skill_labels(): array
{
    return [
        'grammar' => 'Gramática',
        'vocabulary' => 'Vocabulário',
        'speaking' => 'Fala e interação',
        'listening' => 'Compreensão oral',
        'reading' => 'Leitura',
        'writing' => 'Escrita',
        'fluency' => 'Fluência',
        'pronunciation' => 'Pronúncia',
    ];
}

function learning_normalize_skill(string $skill, string $messageType = 'text'): ?string
{
    $value = mb_strtolower(trim($skill));
    $value = str_replace(['-', ' ', '.'], '_', $value);

    $aliases = [
        'grammar_score' => 'grammar',
        'gramatica' => 'grammar',
        'gramática' => 'grammar',
        'vocabulary_score' => 'vocabulary',
        'vocab' => 'vocabulary',
        'vocabulario' => 'vocabulary',
        'vocabulário' => 'vocabulary',
        'speaking_score' => 'speaking',
        'speech' => 'speaking',
        'interaction' => 'speaking',
        'interaction_score' => 'speaking',
        'interacao' => 'speaking',
        'interação' => 'speaking',
        'listening_score' => 'listening',
        'comprehension' => 'listening',
        'comprehension_score' => 'listening',
        'oral_comprehension' => 'listening',
        'reception' => 'listening',
        'reception_score' => 'listening',
        'reading_score' => 'reading',
        'written_reception' => 'reading',
        'writing_score' => 'writing',
        'written_production' => 'writing',
        'production' => $messageType === 'audio' ? 'speaking' : 'writing',
        'production_score' => $messageType === 'audio' ? 'speaking' : 'writing',
        'fluency_score' => 'fluency',
        'fluencia' => 'fluency',
        'fluência' => 'fluency',
        'pronunciation_score' => 'pronunciation',
        'pronuncia' => 'pronunciation',
        'pronúncia' => 'pronunciation',
    ];

    $value = $aliases[$value] ?? $value;

    return in_array($value, learning_skill_codes(), true) ? $value : null;
}

function learning_event_key(string $prefix, array $parts): string
{
    $normalized = array_map(
        static fn(mixed $part): string => preg_replace('/[^a-zA-Z0-9:_-]+/', '-', trim((string)$part)) ?: 'none',
        $parts
    );

    return mb_strimwidth($prefix . ':' . implode(':', $normalized), 0, 220, '');
}

function learning_json(array $value): string
{
    return json_encode(
        $value,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    ) ?: '{}';
}

function learning_record_event(
    PDO $pdo,
    string $studentId,
    string $eventKey,
    string $eventType,
    string $channel = 'system',
    ?string $sessionId = null,
    ?string $sourceId = null,
    int $durationSeconds = 0,
    mixed $score = null,
    int $xpEarned = 0,
    array $eventData = [],
    ?string $occurredAt = null
): void {
    if ($studentId === '' || $eventKey === '' || $eventType === '') {
        return;
    }

    $scoreValue = ($score !== null && $score !== '' && is_numeric($score))
        ? learning_clamp($score)
        : null;

    $stmt = $pdo->prepare(<<<'SQL'
        INSERT INTO student_learning_events(
            student_id, session_id, event_key, event_type, channel,
            source_id, duration_seconds, score, xp_earned, event_data, occurred_at
        ) VALUES(
            :student_id, :session_id, :event_key, :event_type, :channel,
            :source_id, :duration_seconds, :score, :xp_earned,
            CAST(:event_data AS jsonb), COALESCE(CAST(:occurred_at AS timestamptz), NOW())
        )
        ON CONFLICT(event_key)
        DO UPDATE SET
            duration_seconds = GREATEST(student_learning_events.duration_seconds, EXCLUDED.duration_seconds),
            score = COALESCE(EXCLUDED.score, student_learning_events.score),
            xp_earned = GREATEST(student_learning_events.xp_earned, EXCLUDED.xp_earned),
            event_data = student_learning_events.event_data || EXCLUDED.event_data,
            channel = EXCLUDED.channel,
            session_id = COALESCE(EXCLUDED.session_id, student_learning_events.session_id),
            source_id = COALESCE(EXCLUDED.source_id, student_learning_events.source_id),
            occurred_at = LEAST(student_learning_events.occurred_at, EXCLUDED.occurred_at),
            updated_at = NOW()
    SQL);

    $stmt->execute([
        'student_id' => $studentId,
        'session_id' => $sessionId ?: null,
        'event_key' => mb_strimwidth($eventKey, 0, 220, ''),
        'event_type' => mb_strimwidth($eventType, 0, 50, ''),
        'channel' => mb_strimwidth($channel ?: 'system', 0, 40, ''),
        'source_id' => $sourceId ?: null,
        'duration_seconds' => max(0, $durationSeconds),
        'score' => $scoreValue,
        'xp_earned' => max(0, $xpEarned),
        'event_data' => learning_json($eventData),
        'occurred_at' => $occurredAt,
    ]);
}

function learning_record_skill_evidence(
    PDO $pdo,
    string $studentId,
    string $eventKey,
    string $source,
    string $skill,
    mixed $score,
    float $weight = 1.0,
    mixed $confidence = null,
    ?string $evidenceText = null,
    array $evidenceData = [],
    ?string $sessionId = null,
    ?string $studentActivityId = null,
    ?string $observedAt = null
): bool {
    $normalizedSkill = learning_normalize_skill($skill, (string)($evidenceData['message_type'] ?? 'text'));

    if ($normalizedSkill === null || !is_numeric($score)) {
        return false;
    }

    $confidenceValue = null;
    if ($confidence !== null && $confidence !== '' && is_numeric($confidence)) {
        $confidenceValue = (float)$confidence;
        if ($confidenceValue > 0 && $confidenceValue <= 1) {
            $confidenceValue *= 100;
        }
        $confidenceValue = learning_clamp($confidenceValue);
    }

    $stmt = $pdo->prepare(<<<'SQL'
        INSERT INTO student_skill_evidence(
            student_id, session_id, student_activity_id, event_key,
            source, skill_code, score, weight, confidence,
            evidence_text, evidence_data, observed_at
        ) VALUES(
            :student_id, :session_id, :student_activity_id, :event_key,
            :source, :skill_code, :score, :weight, :confidence,
            :evidence_text, CAST(:evidence_data AS jsonb),
            COALESCE(CAST(:observed_at AS timestamptz), NOW())
        )
        ON CONFLICT(event_key)
        DO UPDATE SET
            score = EXCLUDED.score,
            weight = EXCLUDED.weight,
            confidence = COALESCE(EXCLUDED.confidence, student_skill_evidence.confidence),
            evidence_text = COALESCE(EXCLUDED.evidence_text, student_skill_evidence.evidence_text),
            evidence_data = student_skill_evidence.evidence_data || EXCLUDED.evidence_data,
            observed_at = EXCLUDED.observed_at,
            updated_at = NOW()
    SQL);

    $stmt->execute([
        'student_id' => $studentId,
        'session_id' => $sessionId ?: null,
        'student_activity_id' => $studentActivityId ?: null,
        'event_key' => mb_strimwidth($eventKey, 0, 220, ''),
        'source' => mb_strimwidth($source ?: 'unknown', 0, 40, ''),
        'skill_code' => $normalizedSkill,
        'score' => learning_clamp($score),
        'weight' => max(0.1, min(20.0, $weight)),
        'confidence' => $confidenceValue,
        'evidence_text' => $evidenceText !== null ? mb_strimwidth($evidenceText, 0, 3000, '…') : null,
        'evidence_data' => learning_json($evidenceData),
        'observed_at' => $observedAt,
    ]);

    return true;
}

function learning_extract_skill_scores(array $evaluation, string $messageType = 'text'): array
{
    $scores = [];

    $direct = [
        'grammar_score' => 'grammar',
        'vocabulary_score' => 'vocabulary',
        'speaking_score' => 'speaking',
        'listening_score' => 'listening',
        'reading_score' => 'reading',
        'writing_score' => 'writing',
        'fluency_score' => 'fluency',
        'pronunciation_score' => 'pronunciation',
        'interaction_score' => 'speaking',
        'comprehension_score' => 'listening',
        'production_score' => $messageType === 'audio' ? 'speaking' : 'writing',
    ];

    foreach ($direct as $field => $skill) {
        if (array_key_exists($field, $evaluation) && is_numeric($evaluation[$field])) {
            $scores[$skill] = learning_clamp($evaluation[$field]);
        }
    }

    if (array_key_exists('reception_score', $evaluation) && is_numeric($evaluation['reception_score'])) {
        $value = learning_clamp($evaluation['reception_score']);
        $scores['listening'] ??= $value;
        $scores['reading'] ??= $value;
    }

    foreach (($evaluation['skills'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $skill = learning_normalize_skill((string)($item['code'] ?? $item['skill'] ?? ''), $messageType);
        if ($skill !== null && isset($item['score']) && is_numeric($item['score'])) {
            $scores[$skill] = learning_clamp($item['score']);
        }
    }

    foreach (($evaluation['scores'] ?? []) as $key => $value) {
        $normalizedKey = mb_strtolower(trim((string)$key));
        if (in_array($normalizedKey, ['reception', 'reception_score'], true) && is_numeric($value)) {
            $reception = learning_clamp($value);
            $scores['listening'] ??= $reception;
            $scores['reading'] ??= $reception;
            continue;
        }

        $skill = learning_normalize_skill((string)$key, $messageType);
        if ($skill !== null && is_numeric($value)) {
            $scores[$skill] = learning_clamp($value);
        }
    }

    return $scores;
}

function learning_record_evaluation(
    PDO $pdo,
    string $studentId,
    array $evaluation,
    array $context = []
): array {
    $messageType = (string)($context['message_type'] ?? 'text');
    $scores = learning_extract_skill_scores($evaluation, $messageType);
    if ($scores === []) {
        return [];
    }

    $source = (string)($context['source'] ?? 'teacher_evaluation');
    $eventPrefix = (string)($context['event_prefix'] ?? learning_event_key('evaluation', [
        $studentId,
        $context['session_id'] ?? 'none',
        $context['source_id'] ?? microtime(true),
    ]));
    $weight = (float)($context['weight'] ?? 1.0);
    $confidence = $evaluation['confidence_score']
        ?? $evaluation['confidence']
        ?? $context['confidence']
        ?? null;

    foreach ($scores as $skill => $score) {
        learning_record_skill_evidence(
            $pdo,
            $studentId,
            $eventPrefix . ':' . $skill,
            $source,
            $skill,
            $score,
            $weight,
            $confidence,
            (string)($context['evidence_text'] ?? ''),
            array_merge($context['evidence_data'] ?? [], [
                'message_type' => $messageType,
                'raw_skill_score' => $score,
            ]),
            isset($context['session_id']) ? (string)$context['session_id'] : null,
            isset($context['student_activity_id']) ? (string)$context['student_activity_id'] : null,
            isset($context['observed_at']) ? (string)$context['observed_at'] : null
        );
    }

    learning_recalculate_profile_skills($pdo, $studentId);

    return $scores;
}

function learning_recalculate_profile_skills(PDO $pdo, string $studentId): array
{
    $stmt = $pdo->prepare(<<<'SQL'
        SELECT
            skill_code,
            ROUND(
                SUM(
                    score * weight *
                    CASE
                        WHEN observed_at >= NOW() - INTERVAL '30 days' THEN 1.00
                        WHEN observed_at >= NOW() - INTERVAL '90 days' THEN 0.85
                        WHEN observed_at >= NOW() - INTERVAL '180 days' THEN 0.70
                        ELSE 0.55
                    END
                ) /
                NULLIF(SUM(
                    weight *
                    CASE
                        WHEN observed_at >= NOW() - INTERVAL '30 days' THEN 1.00
                        WHEN observed_at >= NOW() - INTERVAL '90 days' THEN 0.85
                        WHEN observed_at >= NOW() - INTERVAL '180 days' THEN 0.70
                        ELSE 0.55
                    END
                ), 0),
                2
            ) AS weighted_score,
            COUNT(*) AS evidence_count,
            MAX(observed_at) AS last_observed_at
        FROM student_skill_evidence
        WHERE student_id = :student_id
        GROUP BY skill_code
    SQL);
    $stmt->execute(['student_id' => $studentId]);
    $rows = $stmt->fetchAll();

    if ($rows === []) {
        return [];
    }

    $columns = [
        'grammar' => 'grammar_score',
        'vocabulary' => 'vocabulary_score',
        'speaking' => 'speaking_score',
        'listening' => 'listening_score',
        'reading' => 'reading_score',
        'writing' => 'writing_score',
        'fluency' => 'fluency_score',
        'pronunciation' => 'pronunciation_score',
    ];

    $set = [];
    $params = ['student_id' => $studentId];
    $result = [];
    $lastEvaluation = null;

    foreach ($rows as $row) {
        $skill = (string)$row['skill_code'];
        if (!isset($columns[$skill])) {
            continue;
        }
        $parameter = 'score_' . $skill;
        $set[] = $columns[$skill] . ' = :' . $parameter;
        $params[$parameter] = learning_clamp($row['weighted_score']);
        $result[$skill] = [
            'score' => learning_clamp($row['weighted_score']),
            'evidence_count' => (int)$row['evidence_count'],
            'last_observed_at' => $row['last_observed_at'],
        ];
        if ($lastEvaluation === null || strtotime((string)$row['last_observed_at']) > strtotime((string)$lastEvaluation)) {
            $lastEvaluation = $row['last_observed_at'];
        }
    }

    if ($set !== []) {
        $set[] = 'last_skill_evaluation_at = :last_skill_evaluation_at';
        $set[] = 'progress_updated_at = NOW()';
        $set[] = 'updated_at = NOW()';
        $params['last_skill_evaluation_at'] = $lastEvaluation;

        $sql = 'UPDATE student_profiles SET ' . implode(', ', $set) . ' WHERE student_id = :student_id';
        $pdo->prepare($sql)->execute($params);
    }

    return $result;
}

function learning_skill_summary(PDO $pdo, string $studentId): array
{
    $stmt = $pdo->prepare(<<<'SQL'
        SELECT skill_code, COUNT(*) AS evidence_count,
               MAX(observed_at) AS last_observed_at,
               COUNT(*) FILTER (WHERE observed_at >= NOW() - INTERVAL '30 days') AS evidence_30d
        FROM student_skill_evidence
        WHERE student_id = :student_id
        GROUP BY skill_code
    SQL);
    $stmt->execute(['student_id' => $studentId]);

    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[(string)$row['skill_code']] = [
            'evidence_count' => (int)$row['evidence_count'],
            'evidence_30d' => (int)$row['evidence_30d'],
            'last_observed_at' => $row['last_observed_at'],
        ];
    }

    return $result;
}

function learning_canonical_key(array $correction): string
{
    $base = trim((string)(
        $correction['canonical_key']
        ?? $correction['topic']
        ?? $correction['category']
        ?? $correction['correction_type']
        ?? $correction['corrected_text']
        ?? $correction['corrected']
        ?? 'other'
    ));

    $base = mb_strtolower($base);
    $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: $base;
    $base = preg_replace('/[^a-z0-9]+/', '_', $base) ?: 'other';

    return mb_strimwidth(trim($base, '_') ?: 'other', 0, 180, '');
}

function learning_sync_correction(
    PDO $pdo,
    string $studentId,
    array $correction,
    array $context = []
): ?string {
    $original = trim((string)($correction['original_text'] ?? $correction['original'] ?? ''));
    $corrected = trim((string)($correction['corrected_text'] ?? $correction['corrected'] ?? ''));

    if ($original === '' && $corrected === '') {
        return null;
    }

    $canonicalKey = learning_canonical_key($correction);
    $category = trim((string)($correction['category'] ?? $correction['correction_type'] ?? 'written')) ?: 'written';
    $topic = trim((string)($correction['topic'] ?? $category)) ?: $category;
    $severity = trim((string)($correction['severity'] ?? 'medium')) ?: 'medium';
    $explanation = trim((string)($correction['explanation'] ?? ''));
    $channel = trim((string)($context['channel'] ?? 'unknown')) ?: 'unknown';
    $sessionId = isset($context['session_id']) && $context['session_id'] !== '' ? (string)$context['session_id'] : null;
    $messageId = isset($context['message_id']) && $context['message_id'] !== '' ? (string)$context['message_id'] : null;
    $eventKey = (string)($context['event_key'] ?? learning_event_key('correction', [
        $studentId,
        $sessionId ?? 'none',
        $messageId ?? 'none',
        $canonicalKey,
        substr(hash('sha256', $original . '|' . $corrected), 0, 12),
    ]));

    // Evita aumentar a recorrência quando o n8n repetir a mesma requisição.
    $eventExistsStmt = $pdo->prepare('SELECT 1 FROM correction_events WHERE event_key = :event_key LIMIT 1');
    $eventExistsStmt->execute(['event_key' => $eventKey]);
    if ($eventExistsStmt->fetchColumn()) {
        return null;
    }

    $existingStmt = $pdo->prepare(<<<'SQL'
        SELECT id
        FROM student_errors
        WHERE student_id = :student_id
          AND canonical_key = :canonical_key
          AND status = 'learning'
        ORDER BY last_seen_at DESC NULLS LAST, created_at DESC
        LIMIT 1
        FOR UPDATE
    SQL);
    $existingStmt->execute([
        'student_id' => $studentId,
        'canonical_key' => $canonicalKey,
    ]);
    $errorId = $existingStmt->fetchColumn();

    if ($errorId) {
        $pdo->prepare(<<<'SQL'
            UPDATE student_errors
            SET category = COALESCE(NULLIF(:category, ''), category),
                topic = COALESCE(NULLIF(:topic, ''), topic),
                original_text = COALESCE(NULLIF(:original_text, ''), original_text),
                corrected_text = COALESCE(NULLIF(:corrected_text, ''), corrected_text),
                explanation = COALESCE(NULLIF(:explanation, ''), explanation),
                severity = COALESCE(NULLIF(:severity, ''), severity),
                occurrences = COALESCE(occurrences, 0) + 1,
                mastery_score = GREATEST(0, COALESCE(mastery_score, 0) - 5),
                source_channel = :source_channel,
                last_seen_at = NOW(),
                next_review_at = NOW() + INTERVAL '1 day',
                resolved_at = NULL,
                resolution_note = NULL
            WHERE id = :id
        SQL)->execute([
            'category' => $category,
            'topic' => $topic,
            'original_text' => $original,
            'corrected_text' => $corrected,
            'explanation' => $explanation,
            'severity' => $severity,
            'source_channel' => $channel,
            'id' => $errorId,
        ]);
    } else {
        $insert = $pdo->prepare(<<<'SQL'
            INSERT INTO student_errors(
                student_id, session_id, message_id, category, topic,
                canonical_key, original_text, corrected_text, explanation,
                severity, occurrences, mastery_score, status, next_review_at,
                source_channel, first_seen_at, last_seen_at
            ) VALUES(
                :student_id, :session_id, :message_id, :category, :topic,
                :canonical_key, :original_text, :corrected_text, :explanation,
                :severity, 1, 0, 'learning', NOW() + INTERVAL '1 day',
                :source_channel, NOW(), NOW()
            )
            RETURNING id
        SQL);
        $insert->execute([
            'student_id' => $studentId,
            'session_id' => $sessionId,
            'message_id' => $messageId,
            'category' => $category,
            'topic' => $topic,
            'canonical_key' => $canonicalKey,
            'original_text' => $original,
            'corrected_text' => $corrected,
            'explanation' => $explanation,
            'severity' => $severity,
            'source_channel' => $channel,
        ]);
        $errorId = $insert->fetchColumn();
    }

    $confidence = isset($correction['confidence_score']) && is_numeric($correction['confidence_score'])
        ? learning_clamp($correction['confidence_score'])
        : null;

    $pdo->prepare(<<<'SQL'
        INSERT INTO correction_events(
            event_key, student_id, session_id, channel, correction_type,
            category, topic, canonical_key, original_text, corrected_text,
            explanation, target_word, detected_word, confidence_score,
            accepted, severity, occurrences, status, updated_at
        ) VALUES(
            :event_key, :student_id, :session_id, :channel, :correction_type,
            :category, :topic, :canonical_key, :original_text, :corrected_text,
            :explanation, :target_word, :detected_word, :confidence_score,
            :accepted, :severity, 1, 'learning', NOW()
        )
        ON CONFLICT DO NOTHING
    SQL)->execute([
        'event_key' => $eventKey,
        'student_id' => $studentId,
        'session_id' => $sessionId,
        'channel' => mb_strimwidth($channel, 0, 30, ''),
        'correction_type' => mb_strimwidth((string)($correction['correction_type'] ?? 'written'), 0, 30, ''),
        'category' => mb_strimwidth($category, 0, 80, ''),
        'topic' => mb_strimwidth($topic, 0, 150, ''),
        'canonical_key' => $canonicalKey,
        'original_text' => $original !== '' ? $original : null,
        'corrected_text' => $corrected !== '' ? $corrected : null,
        'explanation' => $explanation !== '' ? $explanation : null,
        'target_word' => $correction['target_word'] ?? null,
        'detected_word' => $correction['detected_word'] ?? null,
        'confidence_score' => $confidence,
        'accepted' => array_key_exists('accepted', $correction) ? (bool)$correction['accepted'] : true,
        'severity' => mb_strimwidth($severity, 0, 20, ''),
    ]);

    return $errorId ? (string)$errorId : null;
}

function learning_sync_corrections(
    PDO $pdo,
    string $studentId,
    array $corrections,
    array $context = []
): int {
    $saved = 0;
    foreach (array_slice($corrections, 0, 30) as $index => $correction) {
        if (!is_array($correction)) {
            continue;
        }
        $itemContext = $context;
        $itemContext['event_key'] = (string)($context['event_prefix'] ?? learning_event_key('correction', [
            $studentId,
            $context['session_id'] ?? 'none',
            $context['message_id'] ?? 'none',
        ])) . ':' . $index . ':' . learning_canonical_key($correction);
        if (learning_sync_correction($pdo, $studentId, $correction, $itemContext) !== null) {
            $saved++;
        }
    }
    return $saved;
}

function learning_event_totals(PDO $pdo, string $studentId): array
{
    $stmt = $pdo->prepare(<<<'SQL'
        SELECT
            COUNT(*) AS events_total,
            COUNT(*) FILTER (WHERE occurred_at >= NOW() - INTERVAL '7 days') AS events_7d,
            COUNT(*) FILTER (WHERE occurred_at >= NOW() - INTERVAL '30 days') AS events_30d,
            COUNT(DISTINCT occurred_at::date) FILTER (WHERE occurred_at >= NOW() - INTERVAL '30 days') AS active_days_30d,
            COALESCE(SUM(duration_seconds), 0) AS duration_seconds_total,
            COALESCE(SUM(duration_seconds) FILTER (WHERE occurred_at >= NOW() - INTERVAL '7 days'), 0) AS duration_seconds_7d,
            COALESCE(SUM(duration_seconds) FILTER (WHERE occurred_at >= NOW() - INTERVAL '30 days'), 0) AS duration_seconds_30d,
            COALESCE(SUM(xp_earned), 0) AS event_xp_total
        FROM student_learning_events
        WHERE student_id = :student_id
    SQL);
    $stmt->execute(['student_id' => $studentId]);
    return $stmt->fetch() ?: [];
}

function learning_recommendation(array $metrics): array
{
    if (($metrics['diagnostic_status'] ?? 'pending') !== 'completed') {
        return [
            'type' => 'diagnostic',
            'title' => 'Conclua seu diagnóstico adaptativo',
            'description' => 'A Emma precisa de mais algumas evidências para personalizar suas aulas e escolher o melhor idioma de apoio.',
            'action_label' => 'Continuar diagnóstico',
            'action_url' => '/portal/practice.php?mode=diagnostic',
            'priority' => 'high',
        ];
    }

    $skills = $metrics['skills'] ?? [];
    $evidence = $metrics['skill_evidence'] ?? [];
    $measured = [];
    foreach ($skills as $skill => $score) {
        if ((int)($evidence[$skill]['evidence_count'] ?? 0) > 0) {
            $measured[$skill] = (float)$score;
        }
    }

    if ($measured === []) {
        return [
            'type' => 'practice',
            'title' => 'Faça sua primeira prática com a Emma',
            'description' => 'Uma conversa curta já permite começar a medir suas competências e montar recomendações reais.',
            'action_label' => 'Praticar agora',
            'action_url' => '/portal/practice.php',
            'priority' => 'medium',
        ];
    }

    asort($measured);
    $weakest = (string)array_key_first($measured);
    $score = (float)$measured[$weakest];
    $labels = learning_skill_labels();

    $actions = [
        'grammar' => ['Reforce sua gramática em contexto', 'Faça uma atividade curta de construção de frases e aplique a estrutura em uma conversa.', '/portal/activities.php'],
        'vocabulary' => ['Revise seu vocabulário prioritário', 'Pratique as palavras que estão próximas da revisão e use duas delas em uma frase.', '/portal/vocabulary.php'],
        'speaking' => ['Pratique interação com a Emma', 'Responda em inglês com frases curtas. A Emma ajustará o apoio em português conforme sua necessidade.', '/portal/practice.php?mode=conversation'],
        'listening' => ['Treine compreensão oral', 'Ouça uma resposta curta da Emma e explique em português ou inglês o que entendeu.', '/portal/practice.php?mode=conversation'],
        'reading' => ['Faça uma leitura guiada', 'Leia um texto curto e responda uma pergunta de compreensão.', '/portal/activities.php'],
        'writing' => ['Produza uma resposta curta', 'Escreva de três a cinco frases sobre sua rotina e revise as correções recebidas.', '/portal/practice.php?mode=conversation'],
        'fluency' => ['Ganhe fluência com repetição útil', 'Converse por alguns turnos sobre um tema familiar sem buscar perfeição em cada frase.', '/portal/practice.php?mode=conversation'],
        'pronunciation' => ['Pratique sua pronúncia', 'Grave uma frase curta, compare com o modelo e repita apenas os trechos mais difíceis.', '/portal/practice.php?mode=conversation'],
    ];

    [$title, $description, $url] = $actions[$weakest];

    return [
        'type' => $weakest,
        'title' => $title,
        'description' => $description,
        'action_label' => 'Começar prática',
        'action_url' => $url,
        'priority' => $score < 45 ? 'high' : 'medium',
        'skill' => $weakest,
        'skill_label' => $labels[$weakest] ?? $weakest,
        'score' => $score,
    ];
}

function learning_attention_reasons(array $metrics): array
{
    $reasons = [];
    $days = $metrics['days_since_activity'] ?? null;

    if ($days === null) {
        $reasons[] = ['code' => 'not_started', 'severity' => 'high', 'label' => 'Ainda não iniciou os estudos'];
    } elseif ((int)$days >= 30) {
        $reasons[] = ['code' => 'inactive_30d', 'severity' => 'high', 'label' => (int)$days . ' dias sem atividade'];
    } elseif ((int)$days >= 7) {
        $reasons[] = ['code' => 'inactive_7d', 'severity' => 'medium', 'label' => (int)$days . ' dias sem atividade'];
    }

    if (($metrics['diagnostic_status'] ?? 'pending') !== 'completed') {
        $step = (int)($metrics['diagnostic_step'] ?? 0);
        $reasons[] = [
            'code' => 'diagnostic_pending',
            'severity' => $step > 0 ? 'medium' : 'low',
            'label' => $step > 0 ? 'Diagnóstico interrompido na etapa ' . $step : 'Diagnóstico ainda não iniciado',
        ];
    }

    if ((int)($metrics['corrections_recurring'] ?? 0) >= 3) {
        $reasons[] = [
            'code' => 'recurring_errors',
            'severity' => 'medium',
            'label' => (int)$metrics['corrections_recurring'] . ' erros recorrentes em aberto',
        ];
    }

    if ((int)($metrics['activities_pending'] ?? 0) >= 5) {
        $reasons[] = [
            'code' => 'pending_activities',
            'severity' => 'low',
            'label' => (int)$metrics['activities_pending'] . ' atividades pendentes',
        ];
    }

    $dayOfWeek = (int)(new DateTimeImmutable('now'))->format('N');
    if ($dayOfWeek >= 4 && (float)($metrics['week']['goal_percent'] ?? 0) < 25 && ($days !== null && (int)$days <= 7)) {
        $reasons[] = [
            'code' => 'low_week_goal',
            'severity' => 'low',
            'label' => 'Meta semanal abaixo de 25%',
        ];
    }

    $severityRank = ['high' => 0, 'medium' => 1, 'low' => 2];
    usort($reasons, static fn(array $a, array $b): int =>
        ($severityRank[(string)($a['severity'] ?? 'low')] ?? 9)
        <=> ($severityRank[(string)($b['severity'] ?? 'low')] ?? 9)
    );

    return $reasons;
}
