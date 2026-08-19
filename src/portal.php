<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function portal_json(mixed $value, mixed $default = []): mixed
{
    if (is_array($value)) {
        return $value;
    }

    if ($value === null || $value === '') {
        return $default;
    }

    if (is_string($value)) {
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    return $default;
}

function portal_profile(string $studentId): array
{
    $stmt = db()->prepare(<<<'SQL'
        SELECT
            s.id,
            s.name,
            s.phone,
            s.email,
            s.created_at,
            COALESCE(sp.overall_level, 'PRE-A1') AS overall_level,
            COALESCE(sp.estimated_level, 'PRE-A1') AS estimated_level,
            COALESCE(sp.goal, 'Aprender inglês') AS goal,
            COALESCE(sp.diagnostic_status, 'pending') AS diagnostic_status,
            COALESCE(sp.diagnostic_step, 0) AS diagnostic_step,
            sp.diagnostic_started_at,
            sp.diagnostic_completed_at,
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
            sp.last_study_at,
            COALESCE(pref.daily_minutes, 20) AS daily_minutes,
            COALESCE(pref.weekly_days, 5) AS weekly_days,
            COALESCE(pref.focus_mode, 'conversation') AS focus_mode,
            COALESCE(pref.correction_mode, sp.correction_mode, 'balanced') AS correction_mode,
            COALESCE(pref.explanations_language, 'adaptive') AS explanations_language,
            COALESCE(pref.response_mode, 'automatic') AS response_mode,
            COALESCE(pref.voice_name, 'coral') AS voice_name,
            COALESCE(pref.voice_speed, 1.0) AS voice_speed,
            COALESCE(pref.autoplay_audio, TRUE) AS autoplay_audio,
            COALESCE(pref.show_transcription, TRUE) AS show_transcription,
            COALESCE(pref.conversation_topic, 'daily_life') AS conversation_topic,
            COALESCE(pref.conversation_style, 'guided') AS conversation_style,
            COALESCE(pref.conversation_max_turns, 10) AS conversation_max_turns,
            COALESCE(pref.interface_language, 'pt-BR') AS interface_language,
            COALESCE(pref.reminder_enabled, FALSE) AS reminder_enabled,
            pref.reminder_time,
            COALESCE(pref.preferred_topics, '[]'::jsonb) AS preferred_topics,
            COALESCE(pref.avoided_topics, '[]'::jsonb) AS avoided_topics,
            pref.preferred_study_time,
            pref.notes
        FROM students s
        LEFT JOIN student_profiles sp ON sp.student_id = s.id
        LEFT JOIN student_preferences pref ON pref.student_id = s.id
        WHERE s.id = :student_id
        LIMIT 1
    SQL);
    $stmt->execute(['student_id' => $studentId]);

    return $stmt->fetch() ?: [];
}

function portal_latest_diagnostic(string $studentId): ?array
{
    $stmt = db()->prepare(<<<'SQL'
        SELECT
            id,
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
            delivery_channel,
            delivered_at,
            created_at
        FROM diagnostic_reports
        WHERE student_id = :student_id
        ORDER BY created_at DESC
        LIMIT 1
    SQL);
    $stmt->execute(['student_id' => $studentId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    foreach (['strengths', 'weaknesses', 'detected_goals', 'study_plan', 'first_activity', 'scores', 'cefr_evidence', 'recommendations'] as $field) {
        $row[$field] = portal_json($row[$field] ?? null, str_contains($field, 'plan') || $field === 'first_activity' || $field === 'scores' ? [] : []);
    }

    return $row;
}

function portal_active_plan(string $studentId): ?array
{
    $stmt = db()->prepare(<<<'SQL'
        SELECT id, goal, target_level, start_date, end_date, plan_data, status, created_at
        FROM study_plans
        WHERE student_id = :student_id
          AND status = 'active'
        ORDER BY created_at DESC
        LIMIT 1
    SQL);
    $stmt->execute(['student_id' => $studentId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $row['plan_data'] = portal_json($row['plan_data'] ?? null, []);
    return $row;
}

function portal_record_event(
    string $studentId,
    string $eventType,
    string $title,
    ?string $description = null,
    array $eventData = [],
    ?string $sourceId = null
): void {
    try {
        $stmt = db()->prepare(<<<'SQL'
            INSERT INTO study_events(
                student_id, event_type, title, description, event_data, source_id
            ) VALUES(
                :student_id, :event_type, :title, :description,
                CAST(:event_data AS jsonb), :source_id
            )
        SQL);
        $stmt->execute([
            'student_id' => $studentId,
            'event_type' => $eventType,
            'title' => $title,
            'description' => $description,
            'event_data' => json_encode($eventData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'source_id' => $sourceId,
        ]);
    } catch (Throwable $ignored) {
        // O histórico complementar não deve impedir a ação principal.
    }
}

function portal_week_bounds(?DateTimeImmutable $reference = null): array
{
    $reference ??= new DateTimeImmutable('now');
    $monday = $reference->modify('monday this week')->setTime(0, 0);
    $sunday = $reference->modify('sunday this week')->setTime(23, 59, 59);

    return [$monday, $sunday];
}

function portal_normalize_phone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?: '';
}

function portal_level_progress(string $level): int
{
    return match (strtoupper(trim($level))) {
        'PRE-A1' => 8,
        'A1' => 20,
        'A2' => 36,
        'B1' => 55,
        'B2' => 72,
        'C1' => 88,
        'C2' => 100,
        default => 0,
    };
}
