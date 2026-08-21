<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/portal.php';

function progress_clamp(mixed $value): float
{
    // PostgreSQL/PDO pode retornar colunas NUMERIC/DECIMAL como string (ex.: "1.00").
    // Normalizamos aqui antes de aplicar os limites para funcionar com strict_types=1.
    if ($value === null || $value === '' || !is_numeric($value)) {
        return 0.0;
    }

    $number = (float)$value;
    return max(0.0, min(100.0, $number));
}

function progress_skill_values(array $profile): array
{
    return [
        'grammar' => progress_clamp($profile['grammar_score'] ?? 0),
        'vocabulary' => progress_clamp($profile['vocabulary_score'] ?? 0),
        'speaking' => progress_clamp($profile['speaking_score'] ?? 0),
        'listening' => progress_clamp($profile['listening_score'] ?? 0),
        'reading' => progress_clamp($profile['reading_score'] ?? 0),
        'writing' => progress_clamp($profile['writing_score'] ?? 0),
        'fluency' => progress_clamp($profile['fluency_score'] ?? 0),
        'pronunciation' => progress_clamp($profile['pronunciation_score'] ?? 0),
    ];
}

function progress_skill_average(array $skills): float
{
    // Só entram competências já medidas (>0). Evita transformar campos ainda não avaliados em nota zero.
    $measured = array_values(array_filter($skills, static fn($score) => (float)$score > 0));
    if (!$measured) return 0.0;
    return round(array_sum($measured) / count($measured), 2);
}

function progress_activity_days(PDO $pdo, string $studentId, int $days = 120): array
{
    $days = max(7, min(365, $days));
    $stmt = $pdo->prepare(<<<SQL
        SELECT DISTINCT activity_day
        FROM (
            SELECT created_at::date AS activity_day
            FROM messages
            WHERE student_id = :student_id
              AND created_at >= CURRENT_DATE - INTERVAL '{$days} days'
              AND COALESCE(role, '') <> 'teacher'

            UNION

            SELECT completed_at::date AS activity_day
            FROM student_activities
            WHERE student_id = :student_id
              AND status = 'completed'
              AND completed_at >= CURRENT_DATE - INTERVAL '{$days} days'

            UNION

            SELECT created_at::date AS activity_day
            FROM voice_conversations
            WHERE student_id = :student_id
              AND created_at >= CURRENT_DATE - INTERVAL '{$days} days'

            UNION

            SELECT created_at::date AS activity_day
            FROM sessions
            WHERE student_id = :student_id
              AND created_at >= CURRENT_DATE - INTERVAL '{$days} days'
        ) d
        WHERE activity_day IS NOT NULL
        ORDER BY activity_day DESC
    SQL);
    $stmt->execute(['student_id' => $studentId]);
    return array_map(static fn($row) => (string)$row['activity_day'], $stmt->fetchAll());
}

function progress_calculate_streak(array $activityDays): int
{
    if (!$activityDays) return 0;

    $unique = array_values(array_unique($activityDays));
    rsort($unique);
    $latest = new DateTimeImmutable($unique[0]);
    $today = new DateTimeImmutable('today');
    $diff = (int)$today->diff($latest)->format('%r%a');

    // A sequência continua válida durante o dia atual quando o último estudo foi ontem.
    if ($diff < -1 || $diff > 0) return 0;

    $expected = $latest;
    $streak = 0;
    foreach ($unique as $day) {
        $date = new DateTimeImmutable($day);
        if ($date->format('Y-m-d') !== $expected->format('Y-m-d')) break;
        $streak++;
        $expected = $expected->modify('-1 day');
    }

    return $streak;
}

function progress_week_data(PDO $pdo, string $studentId): array
{
    [$weekStart, $weekEnd] = portal_week_bounds();
    $start = $weekStart->format('Y-m-d');
    $end = $weekEnd->format('Y-m-d');

    $goalStmt = $pdo->prepare(<<<'SQL'
        SELECT target_minutes, target_activities, target_words,
               completed_minutes, completed_activities, learned_words
        FROM weekly_goals
        WHERE student_id = :student_id AND week_start = :week_start
        LIMIT 1
    SQL);
    $goalStmt->execute(['student_id' => $studentId, 'week_start' => $start]);
    $saved = $goalStmt->fetch() ?: [
        'target_minutes' => 100,
        'target_activities' => 4,
        'target_words' => 20,
        'completed_minutes' => 0,
        'completed_activities' => 0,
        'learned_words' => 0,
    ];

    $activityStmt = $pdo->prepare(<<<'SQL'
        SELECT
            COUNT(*) FILTER (WHERE sa.status = 'completed') AS completed,
            COALESCE(SUM(a.estimated_minutes) FILTER (WHERE sa.status = 'completed'), 0) AS minutes,
            COALESCE(AVG(sa.score) FILTER (WHERE sa.status = 'completed' AND sa.score IS NOT NULL), 0) AS avg_score
        FROM student_activities sa
        JOIN activities a ON a.id = sa.activity_id
        WHERE sa.student_id = :student_id
          AND sa.completed_at::date BETWEEN :week_start AND :week_end
    SQL);
    $activityStmt->execute(['student_id' => $studentId, 'week_start' => $start, 'week_end' => $end]);
    $activity = $activityStmt->fetch() ?: ['completed' => 0, 'minutes' => 0, 'avg_score' => 0];

    $voiceStmt = $pdo->prepare(<<<'SQL'
        SELECT COALESCE(SUM(student_audio_duration_seconds), 0) / 60.0 AS minutes
        FROM voice_conversations
        WHERE student_id = :student_id
          AND created_at::date BETWEEN :week_start AND :week_end
    SQL);
    $voiceStmt->execute(['student_id' => $studentId, 'week_start' => $start, 'week_end' => $end]);
    $voiceMinutes = (float)($voiceStmt->fetchColumn() ?: 0);

    $wordStmt = $pdo->prepare(<<<'SQL'
        SELECT COUNT(*)
        FROM student_vocabulary
        WHERE student_id = :student_id
          AND first_seen_at::date BETWEEN :week_start AND :week_end
    SQL);
    $wordStmt->execute(['student_id' => $studentId, 'week_start' => $start, 'week_end' => $end]);
    $newWords = (int)$wordStmt->fetchColumn();

    $derivedMinutes = (int)round((float)$activity['minutes'] + $voiceMinutes);
    $completedMinutes = max((int)$saved['completed_minutes'], $derivedMinutes);
    $completedActivities = max((int)$saved['completed_activities'], (int)$activity['completed']);
    $learnedWords = max((int)$saved['learned_words'], $newWords);

    $targetMinutes = max(1, (int)$saved['target_minutes']);
    $targetActivities = max(1, (int)$saved['target_activities']);
    $targetWords = max(1, (int)$saved['target_words']);

    $minutesPct = min(100, ($completedMinutes / $targetMinutes) * 100);
    $activitiesPct = min(100, ($completedActivities / $targetActivities) * 100);
    $wordsPct = min(100, ($learnedWords / $targetWords) * 100);
    $goalPercent = round(($minutesPct + $activitiesPct + $wordsPct) / 3, 1);

    return [
        'week_start' => $start,
        'week_end' => $end,
        'target_minutes' => $targetMinutes,
        'target_activities' => $targetActivities,
        'target_words' => $targetWords,
        'completed_minutes' => $completedMinutes,
        'completed_activities' => $completedActivities,
        'learned_words' => $learnedWords,
        'minutes_pct' => round($minutesPct, 1),
        'activities_pct' => round($activitiesPct, 1),
        'words_pct' => round($wordsPct, 1),
        'goal_percent' => $goalPercent,
        'activity_average_score' => round((float)$activity['avg_score'], 1),
        'derived_minutes' => $derivedMinutes,
        'voice_minutes' => round($voiceMinutes, 1),
    ];
}

function progress_sync_weekly_goal(PDO $pdo, string $studentId, array $week): void
{
    $stmt = $pdo->prepare(<<<'SQL'
        INSERT INTO weekly_goals(
            student_id, week_start, week_end,
            completed_minutes, completed_activities, learned_words
        ) VALUES(
            :student_id, :week_start, :week_end,
            :completed_minutes, :completed_activities, :learned_words
        )
        ON CONFLICT(student_id, week_start)
        DO UPDATE SET
            completed_minutes = GREATEST(weekly_goals.completed_minutes, EXCLUDED.completed_minutes),
            completed_activities = GREATEST(weekly_goals.completed_activities, EXCLUDED.completed_activities),
            learned_words = GREATEST(weekly_goals.learned_words, EXCLUDED.learned_words),
            updated_at = NOW()
    SQL);
    $stmt->execute([
        'student_id' => $studentId,
        'week_start' => $week['week_start'],
        'week_end' => $week['week_end'],
        'completed_minutes' => $week['completed_minutes'],
        'completed_activities' => $week['completed_activities'],
        'learned_words' => $week['learned_words'],
    ]);
}

function progress_student_metrics(string $studentId, bool $sync = false): array
{
    $pdo = db();
    $profile = portal_profile($studentId);
    if (!$profile) return [];

    $skills = progress_skill_values($profile);
    $skillAverage = progress_skill_average($skills);
    $week = progress_week_data($pdo, $studentId);

    $stmt = $pdo->prepare(<<<'SQL'
        SELECT
            (SELECT COUNT(*) FROM sessions WHERE student_id = :student_id) AS sessions_total,
            (SELECT COUNT(*) FROM sessions WHERE student_id = :student_id AND created_at >= NOW() - INTERVAL '7 days') AS sessions_7d,
            (SELECT COUNT(*) FROM sessions WHERE student_id = :student_id AND created_at >= NOW() - INTERVAL '30 days') AS sessions_30d,
            (SELECT COUNT(*) FROM messages WHERE student_id = :student_id) AS messages_total,
            (SELECT COUNT(*) FROM messages WHERE student_id = :student_id AND created_at >= NOW() - INTERVAL '7 days') AS messages_7d,
            (SELECT COUNT(*) FROM messages WHERE student_id = :student_id AND created_at >= NOW() - INTERVAL '30 days') AS messages_30d,
            (SELECT COUNT(*) FROM student_activities WHERE student_id = :student_id) AS activities_total,
            (SELECT COUNT(*) FROM student_activities WHERE student_id = :student_id AND status = 'completed') AS activities_completed,
            (SELECT COUNT(*) FROM student_activities WHERE student_id = :student_id AND status = 'pending') AS activities_pending,
            (SELECT COUNT(*) FROM student_activities WHERE student_id = :student_id AND status = 'completed' AND completed_at >= NOW() - INTERVAL '7 days') AS activities_7d,
            (SELECT COALESCE(AVG(score),0) FROM student_activities WHERE student_id = :student_id AND status = 'completed' AND score IS NOT NULL) AS activity_average_score,
            (SELECT COUNT(*) FROM student_vocabulary WHERE student_id = :student_id) AS vocabulary_total,
            (SELECT COUNT(*) FROM student_vocabulary WHERE student_id = :student_id AND status = 'mastered') AS vocabulary_mastered,
            (SELECT COUNT(*) FROM student_vocabulary WHERE student_id = :student_id AND status IN ('learning','review')) AS vocabulary_learning,
            (SELECT COUNT(*) FROM student_vocabulary WHERE student_id = :student_id AND status IN ('learning','review') AND (next_review_at IS NULL OR next_review_at <= NOW())) AS vocabulary_due,
            (SELECT COALESCE(AVG(mastery_score),0) FROM student_vocabulary WHERE student_id = :student_id) AS vocabulary_mastery_average,
            (SELECT COUNT(*) FROM student_errors WHERE student_id = :student_id) AS corrections_total,
            (SELECT COUNT(*) FROM student_errors WHERE student_id = :student_id AND status = 'learning') AS corrections_open,
            (SELECT COUNT(*) FROM student_errors WHERE student_id = :student_id AND status = 'learning' AND (next_review_at IS NULL OR next_review_at <= NOW())) AS corrections_due,
            (SELECT COUNT(*) FROM student_achievements WHERE student_id = :student_id) AS achievements_total,
            (SELECT COUNT(*) FROM weekly_reports WHERE student_id = :student_id) AS reports_total,
            (SELECT COUNT(*) FROM diagnostic_reports WHERE student_id = :student_id) AS diagnostics_total,
            (SELECT COALESCE(SUM(student_audio_duration_seconds),0) / 60.0 FROM voice_conversations WHERE student_id = :student_id) AS voice_minutes_total,
            (
                SELECT MAX(ts) FROM (
                    SELECT MAX(created_at) AS ts FROM messages WHERE student_id = :student_id
                    UNION ALL SELECT MAX(completed_at) FROM student_activities WHERE student_id = :student_id
                    UNION ALL SELECT MAX(created_at) FROM sessions WHERE student_id = :student_id
                    UNION ALL SELECT MAX(created_at) FROM voice_conversations WHERE student_id = :student_id
                    UNION ALL SELECT MAX(created_at) FROM study_events WHERE student_id = :student_id
                ) x
            ) AS last_activity_at
    SQL);
    $stmt->execute(['student_id' => $studentId]);
    $agg = $stmt->fetch() ?: [];

    $activityDays = progress_activity_days($pdo, $studentId);
    $streak = progress_calculate_streak($activityDays);
    $lastActivity = $agg['last_activity_at'] ?: ($profile['last_study_at'] ?? null);

    $activitiesTotal = (int)($agg['activities_total'] ?? 0);
    $activitiesCompleted = (int)($agg['activities_completed'] ?? 0);
    $activityCompletionRate = $activitiesTotal > 0 ? round(($activitiesCompleted / $activitiesTotal) * 100, 1) : 0.0;

    $vocabularyTotal = (int)($agg['vocabulary_total'] ?? 0);
    $vocabularyMastered = (int)($agg['vocabulary_mastered'] ?? 0);
    $vocabularyMasteryRate = $vocabularyTotal > 0 ? round(($vocabularyMastered / $vocabularyTotal) * 100, 1) : 0.0;

    $correctionsTotal = (int)($agg['corrections_total'] ?? 0);
    $correctionsOpen = (int)($agg['corrections_open'] ?? 0);
    $correctionsResolvedRate = $correctionsTotal > 0 ? round((($correctionsTotal - $correctionsOpen) / $correctionsTotal) * 100, 1) : 0.0;

    $daysSince = null;
    if ($lastActivity) {
        $daysSince = max(0, (int)(new DateTimeImmutable((string)$lastActivity))->diff(new DateTimeImmutable('now'))->format('%a'));
    }
    $engagementStatus = $daysSince === null ? 'not_started' : ($daysSince <= 3 ? 'active' : ($daysSince <= 7 ? 'attention' : 'inactive'));

    $metrics = array_merge($profile, $agg, [
        'skills' => $skills,
        'skills_measured' => count(array_filter($skills, static fn($v) => $v > 0)),
        'skill_average' => $skillAverage,
        'week' => $week,
        'streak_days_real' => $streak,
        'last_activity_at' => $lastActivity,
        'days_since_activity' => $daysSince,
        'engagement_status' => $engagementStatus,
        'activity_completion_rate' => $activityCompletionRate,
        'vocabulary_mastery_rate' => $vocabularyMasteryRate,
        'corrections_resolved_rate' => $correctionsResolvedRate,
        'pending_total' => (int)($agg['activities_pending'] ?? 0) + (int)($agg['vocabulary_due'] ?? 0) + (int)($agg['corrections_due'] ?? 0),
    ]);

    if ($sync) {
        progress_sync_weekly_goal($pdo, $studentId, $week);
        $pdo->prepare(<<<'SQL'
            UPDATE student_profiles
            SET streak_days = :streak_days,
                last_study_at = COALESCE(:last_activity_at, last_study_at),
                updated_at = NOW()
            WHERE student_id = :student_id
        SQL)->execute([
            'streak_days' => $streak,
            'last_activity_at' => $lastActivity,
            'student_id' => $studentId,
        ]);
        progress_capture_snapshot($metrics);
    }

    return $metrics;
}

function progress_capture_snapshot(array $metrics): void
{
    if (empty($metrics['id'])) return;
    $pdo = db();
    $week = $metrics['week'] ?? [];
    $skills = $metrics['skills'] ?? [];

    $stmt = $pdo->prepare(<<<'SQL'
        INSERT INTO student_progress_snapshots(
            student_id, snapshot_date, overall_level, skill_average,
            grammar_score, vocabulary_score, speaking_score, listening_score,
            reading_score, writing_score, fluency_score, pronunciation_score,
            xp, streak_days, sessions_total, sessions_30d, messages_30d,
            activities_completed, activity_average_score,
            vocabulary_total, vocabulary_mastered, vocabulary_mastery_average,
            corrections_open, weekly_minutes, weekly_activities, weekly_words,
            weekly_goal_percent, last_activity_at
        ) VALUES(
            :student_id, CURRENT_DATE, :overall_level, :skill_average,
            :grammar_score, :vocabulary_score, :speaking_score, :listening_score,
            :reading_score, :writing_score, :fluency_score, :pronunciation_score,
            :xp, :streak_days, :sessions_total, :sessions_30d, :messages_30d,
            :activities_completed, :activity_average_score,
            :vocabulary_total, :vocabulary_mastered, :vocabulary_mastery_average,
            :corrections_open, :weekly_minutes, :weekly_activities, :weekly_words,
            :weekly_goal_percent, :last_activity_at
        )
        ON CONFLICT(student_id, snapshot_date)
        DO UPDATE SET
            overall_level = EXCLUDED.overall_level,
            skill_average = EXCLUDED.skill_average,
            grammar_score = EXCLUDED.grammar_score,
            vocabulary_score = EXCLUDED.vocabulary_score,
            speaking_score = EXCLUDED.speaking_score,
            listening_score = EXCLUDED.listening_score,
            reading_score = EXCLUDED.reading_score,
            writing_score = EXCLUDED.writing_score,
            fluency_score = EXCLUDED.fluency_score,
            pronunciation_score = EXCLUDED.pronunciation_score,
            xp = EXCLUDED.xp,
            streak_days = EXCLUDED.streak_days,
            sessions_total = EXCLUDED.sessions_total,
            sessions_30d = EXCLUDED.sessions_30d,
            messages_30d = EXCLUDED.messages_30d,
            activities_completed = EXCLUDED.activities_completed,
            activity_average_score = EXCLUDED.activity_average_score,
            vocabulary_total = EXCLUDED.vocabulary_total,
            vocabulary_mastered = EXCLUDED.vocabulary_mastered,
            vocabulary_mastery_average = EXCLUDED.vocabulary_mastery_average,
            corrections_open = EXCLUDED.corrections_open,
            weekly_minutes = EXCLUDED.weekly_minutes,
            weekly_activities = EXCLUDED.weekly_activities,
            weekly_words = EXCLUDED.weekly_words,
            weekly_goal_percent = EXCLUDED.weekly_goal_percent,
            last_activity_at = EXCLUDED.last_activity_at,
            updated_at = NOW()
    SQL);

    $stmt->execute([
        'student_id' => $metrics['id'],
        'overall_level' => $metrics['overall_level'] ?? 'PRE-A1',
        'skill_average' => $metrics['skill_average'] ?? 0,
        'grammar_score' => $skills['grammar'] ?? 0,
        'vocabulary_score' => $skills['vocabulary'] ?? 0,
        'speaking_score' => $skills['speaking'] ?? 0,
        'listening_score' => $skills['listening'] ?? 0,
        'reading_score' => $skills['reading'] ?? 0,
        'writing_score' => $skills['writing'] ?? 0,
        'fluency_score' => $skills['fluency'] ?? 0,
        'pronunciation_score' => $skills['pronunciation'] ?? 0,
        'xp' => (int)($metrics['xp'] ?? 0),
        'streak_days' => (int)($metrics['streak_days_real'] ?? 0),
        'sessions_total' => (int)($metrics['sessions_total'] ?? 0),
        'sessions_30d' => (int)($metrics['sessions_30d'] ?? 0),
        'messages_30d' => (int)($metrics['messages_30d'] ?? 0),
        'activities_completed' => (int)($metrics['activities_completed'] ?? 0),
        'activity_average_score' => (float)($metrics['activity_average_score'] ?? 0),
        'vocabulary_total' => (int)($metrics['vocabulary_total'] ?? 0),
        'vocabulary_mastered' => (int)($metrics['vocabulary_mastered'] ?? 0),
        'vocabulary_mastery_average' => (float)($metrics['vocabulary_mastery_average'] ?? 0),
        'corrections_open' => (int)($metrics['corrections_open'] ?? 0),
        'weekly_minutes' => (int)($week['completed_minutes'] ?? 0),
        'weekly_activities' => (int)($week['completed_activities'] ?? 0),
        'weekly_words' => (int)($week['learned_words'] ?? 0),
        'weekly_goal_percent' => (float)($week['goal_percent'] ?? 0),
        'last_activity_at' => $metrics['last_activity_at'] ?? null,
    ]);
}

function progress_snapshot_history(string $studentId, int $days = 30): array
{
    $days = max(7, min(365, $days));
    $stmt = db()->prepare(<<<SQL
        SELECT snapshot_date, overall_level, skill_average, xp, streak_days,
               activities_completed, vocabulary_mastered, weekly_goal_percent
        FROM student_progress_snapshots
        WHERE student_id = :student_id
          AND snapshot_date >= CURRENT_DATE - INTERVAL '{$days} days'
        ORDER BY snapshot_date
    SQL);
    $stmt->execute(['student_id' => $studentId]);
    return $stmt->fetchAll();
}

function progress_student_daily_activity(string $studentId, int $days = 14): array
{
    $days = max(7, min(60, $days));
    $offset = $days - 1;
    $stmt = db()->prepare(<<<SQL
        WITH calendar AS (
            SELECT generate_series(CURRENT_DATE - INTERVAL '{$offset} days', CURRENT_DATE, INTERVAL '1 day')::date AS day
        ), session_data AS (
            SELECT created_at::date AS day, COUNT(*) AS sessions
            FROM sessions
            WHERE student_id = :student_id AND created_at >= CURRENT_DATE - INTERVAL '{$days} days'
            GROUP BY created_at::date
        ), activity_data AS (
            SELECT completed_at::date AS day, COUNT(*) AS activities
            FROM student_activities
            WHERE student_id = :student_id AND status = 'completed'
              AND completed_at >= CURRENT_DATE - INTERVAL '{$days} days'
            GROUP BY completed_at::date
        ), message_data AS (
            SELECT created_at::date AS day, COUNT(*) AS messages
            FROM messages
            WHERE student_id = :student_id AND created_at >= CURRENT_DATE - INTERVAL '{$days} days'
              AND COALESCE(role,'') <> 'teacher'
            GROUP BY created_at::date
        )
        SELECT c.day,
               COALESCE(s.sessions,0) AS sessions,
               COALESCE(a.activities,0) AS activities,
               COALESCE(m.messages,0) AS messages
        FROM calendar c
        LEFT JOIN session_data s ON s.day = c.day
        LEFT JOIN activity_data a ON a.day = c.day
        LEFT JOIN message_data m ON m.day = c.day
        ORDER BY c.day
    SQL);
    $stmt->execute(['student_id' => $studentId]);
    return $stmt->fetchAll();
}

function progress_all_student_metrics(bool $activeOnly = true): array
{
    $pdo = db();
    $sql = 'SELECT id FROM students' . ($activeOnly ? " WHERE status='active'" : '') . ' ORDER BY name';
    $ids = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    $rows = [];
    foreach ($ids as $id) {
        $metrics = progress_student_metrics((string)$id, false);
        if ($metrics) $rows[] = $metrics;
    }
    return $rows;
}

function progress_admin_summary(?array $rows = null): array
{
    $rows ??= progress_all_student_metrics(true);
    $total = count($rows);
    $active7 = 0;
    $attention = 0;
    $diagnosticCompleted = 0;
    $skillSum = 0.0;
    $skillCount = 0;
    $weekSum = 0.0;
    $activityCompleted = 0;
    $activityTotal = 0;
    $sessions7 = 0;
    $wordsMastered = 0;
    $levels = ['PRE-A1'=>0,'A1'=>0,'A2'=>0,'B1'=>0,'B2'=>0,'C1'=>0,'C2'=>0];

    foreach ($rows as $row) {
        $days = $row['days_since_activity'];
        if ($days !== null && $days <= 7) $active7++;
        if (in_array($row['engagement_status'], ['attention','inactive','not_started'], true)) $attention++;
        if (($row['diagnostic_status'] ?? '') === 'completed') $diagnosticCompleted++;
        if (($row['skills_measured'] ?? 0) > 0) {
            $skillSum += (float)$row['skill_average'];
            $skillCount++;
        }
        $weekSum += (float)($row['week']['goal_percent'] ?? 0);
        $activityCompleted += (int)($row['activities_completed'] ?? 0);
        $activityTotal += (int)($row['activities_total'] ?? 0);
        $sessions7 += (int)($row['sessions_7d'] ?? 0);
        $wordsMastered += (int)($row['vocabulary_mastered'] ?? 0);
        $level = strtoupper((string)($row['overall_level'] ?? 'PRE-A1'));
        if (!array_key_exists($level, $levels)) $levels[$level] = 0;
        $levels[$level]++;
    }

    return [
        'students_total' => $total,
        'active_7d' => $active7,
        'active_7d_percent' => $total ? round(($active7 / $total) * 100, 1) : 0,
        'needs_attention' => $attention,
        'diagnostic_completed' => $diagnosticCompleted,
        'diagnostic_completion_percent' => $total ? round(($diagnosticCompleted / $total) * 100, 1) : 0,
        'skill_average' => $skillCount ? round($skillSum / $skillCount, 1) : 0,
        'weekly_goal_average' => $total ? round($weekSum / $total, 1) : 0,
        'activity_completion_percent' => $activityTotal ? round(($activityCompleted / $activityTotal) * 100, 1) : 0,
        'sessions_7d' => $sessions7,
        'words_mastered' => $wordsMastered,
        'levels' => $levels,
    ];
}

function progress_admin_daily_activity(int $days = 14): array
{
    $days = max(7, min(60, $days));
    $offset = $days - 1;
    $stmt = db()->query(<<<SQL
        WITH calendar AS (
            SELECT generate_series(CURRENT_DATE - INTERVAL '{$offset} days', CURRENT_DATE, INTERVAL '1 day')::date AS day
        ), session_data AS (
            SELECT created_at::date AS day, COUNT(*) AS sessions, COUNT(DISTINCT student_id) AS students
            FROM sessions
            WHERE created_at >= CURRENT_DATE - INTERVAL '{$days} days'
            GROUP BY created_at::date
        ), activity_data AS (
            SELECT completed_at::date AS day, COUNT(*) AS activities
            FROM student_activities
            WHERE status = 'completed' AND completed_at >= CURRENT_DATE - INTERVAL '{$days} days'
            GROUP BY completed_at::date
        )
        SELECT c.day,
               COALESCE(s.sessions,0) AS sessions,
               COALESCE(s.students,0) AS students,
               COALESCE(a.activities,0) AS activities
        FROM calendar c
        LEFT JOIN session_data s ON s.day = c.day
        LEFT JOIN activity_data a ON a.day = c.day
        ORDER BY c.day
    SQL);
    return $stmt->fetchAll();
}

function progress_engagement_label(string $status): string
{
    return match ($status) {
        'active' => 'Ativo',
        'attention' => 'Atenção',
        'inactive' => 'Inativo',
        default => 'Ainda não iniciou',
    };
}

function progress_engagement_class(string $status): string
{
    return match ($status) {
        'active' => 'success',
        'attention' => 'warning',
        'inactive' => 'danger',
        default => 'neutral',
    };
}

function progress_refresh_after_event(string $studentId): void
{
    try {
        progress_student_metrics($studentId, true);
    } catch (Throwable $e) {
        error_log('[PROGRESS REFRESH] ' . $e->getMessage());
    }
}
