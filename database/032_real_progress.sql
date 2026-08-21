-- RS English v14 - Progresso real de alunos e visão administrativa
-- Execute após a migration 031.

CREATE TABLE IF NOT EXISTS student_progress_snapshots (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    student_id UUID NOT NULL REFERENCES students(id) ON DELETE CASCADE,
    snapshot_date DATE NOT NULL DEFAULT CURRENT_DATE,
    overall_level VARCHAR(10) NOT NULL DEFAULT 'PRE-A1',
    skill_average NUMERIC(5,2) NOT NULL DEFAULT 0,
    grammar_score NUMERIC(5,2) NOT NULL DEFAULT 0,
    vocabulary_score NUMERIC(5,2) NOT NULL DEFAULT 0,
    speaking_score NUMERIC(5,2) NOT NULL DEFAULT 0,
    listening_score NUMERIC(5,2) NOT NULL DEFAULT 0,
    reading_score NUMERIC(5,2) NOT NULL DEFAULT 0,
    writing_score NUMERIC(5,2) NOT NULL DEFAULT 0,
    fluency_score NUMERIC(5,2) NOT NULL DEFAULT 0,
    pronunciation_score NUMERIC(5,2) NOT NULL DEFAULT 0,
    xp INTEGER NOT NULL DEFAULT 0,
    streak_days INTEGER NOT NULL DEFAULT 0,
    sessions_total INTEGER NOT NULL DEFAULT 0,
    sessions_30d INTEGER NOT NULL DEFAULT 0,
    messages_30d INTEGER NOT NULL DEFAULT 0,
    activities_completed INTEGER NOT NULL DEFAULT 0,
    activity_average_score NUMERIC(5,2) NOT NULL DEFAULT 0,
    vocabulary_total INTEGER NOT NULL DEFAULT 0,
    vocabulary_mastered INTEGER NOT NULL DEFAULT 0,
    vocabulary_mastery_average NUMERIC(5,2) NOT NULL DEFAULT 0,
    corrections_open INTEGER NOT NULL DEFAULT 0,
    weekly_minutes INTEGER NOT NULL DEFAULT 0,
    weekly_activities INTEGER NOT NULL DEFAULT 0,
    weekly_words INTEGER NOT NULL DEFAULT 0,
    weekly_goal_percent NUMERIC(5,2) NOT NULL DEFAULT 0,
    last_activity_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(student_id, snapshot_date)
);

CREATE INDEX IF NOT EXISTS idx_progress_snapshots_student_date
ON student_progress_snapshots(student_id, snapshot_date DESC);

CREATE INDEX IF NOT EXISTS idx_progress_snapshots_date
ON student_progress_snapshots(snapshot_date DESC);

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'rsenglish_app') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON student_progress_snapshots TO rsenglish_app;
    END IF;
END $$;
