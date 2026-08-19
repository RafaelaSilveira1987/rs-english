-- RS English v11 - Painel completo do aluno
-- Execute após as migrations anteriores.

ALTER TABLE student_preferences
    ADD COLUMN IF NOT EXISTS interface_language VARCHAR(10) NOT NULL DEFAULT 'pt-BR',
    ADD COLUMN IF NOT EXISTS reminder_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS reminder_time TIME,
    ADD COLUMN IF NOT EXISTS explanations_language VARCHAR(30) NOT NULL DEFAULT 'adaptive';

ALTER TABLE student_activities
    ADD COLUMN IF NOT EXISTS started_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS last_attempt_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS attempts INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS answer_text TEXT,
    ADD COLUMN IF NOT EXISTS answer_data JSONB NOT NULL DEFAULT '{}'::jsonb,
    ADD COLUMN IF NOT EXISTS feedback TEXT;

ALTER TABLE diagnostic_reports
    ADD COLUMN IF NOT EXISTS scores JSONB NOT NULL DEFAULT '{}'::jsonb,
    ADD COLUMN IF NOT EXISTS cefr_evidence JSONB NOT NULL DEFAULT '[]'::jsonb,
    ADD COLUMN IF NOT EXISTS recommendations JSONB NOT NULL DEFAULT '[]'::jsonb,
    ADD COLUMN IF NOT EXISTS raw_payload JSONB NOT NULL DEFAULT '{}'::jsonb;

ALTER TABLE correction_events
    ADD COLUMN IF NOT EXISTS topic VARCHAR(150),
    ADD COLUMN IF NOT EXISTS severity VARCHAR(20) NOT NULL DEFAULT 'medium';

CREATE TABLE IF NOT EXISTS activity_attempts (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    student_id UUID NOT NULL REFERENCES students(id) ON DELETE CASCADE,
    student_activity_id UUID NOT NULL REFERENCES student_activities(id) ON DELETE CASCADE,
    attempt_number INTEGER NOT NULL DEFAULT 1,
    answer_text TEXT,
    answer_data JSONB NOT NULL DEFAULT '{}'::jsonb,
    score NUMERIC(5,2),
    feedback TEXT,
    evaluation_data JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS study_events (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    student_id UUID NOT NULL REFERENCES students(id) ON DELETE CASCADE,
    event_type VARCHAR(50) NOT NULL,
    title VARCHAR(180) NOT NULL,
    description TEXT,
    event_data JSONB NOT NULL DEFAULT '{}'::jsonb,
    source_id UUID,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_activity_attempts_student_created
ON activity_attempts(student_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_activity_attempts_activity_number
ON activity_attempts(student_activity_id, attempt_number DESC);

CREATE INDEX IF NOT EXISTS idx_study_events_student_created
ON study_events(student_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_study_events_type_created
ON study_events(student_id, event_type, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_correction_events_student_type_created
ON correction_events(student_id, correction_type, created_at DESC);

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'rsenglish_app') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON activity_attempts, study_events TO rsenglish_app;
        GRANT SELECT, INSERT, UPDATE, DELETE ON student_preferences, student_activities, diagnostic_reports, correction_events TO rsenglish_app;
    END IF;
END $$;
