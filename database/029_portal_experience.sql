-- RS English v10.6 - Portal do professor e do aluno
-- Compatibilidade das preferências e índices das telas.

ALTER TABLE student_preferences
    ADD COLUMN IF NOT EXISTS explanations_language VARCHAR(30) NOT NULL DEFAULT 'adaptive',
    ADD COLUMN IF NOT EXISTS response_mode VARCHAR(30) NOT NULL DEFAULT 'automatic',
    ADD COLUMN IF NOT EXISTS voice_name VARCHAR(50) NOT NULL DEFAULT 'coral',
    ADD COLUMN IF NOT EXISTS voice_speed NUMERIC(4,2) NOT NULL DEFAULT 1.00,
    ADD COLUMN IF NOT EXISTS autoplay_audio BOOLEAN NOT NULL DEFAULT TRUE,
    ADD COLUMN IF NOT EXISTS show_transcription BOOLEAN NOT NULL DEFAULT TRUE,
    ADD COLUMN IF NOT EXISTS conversation_topic VARCHAR(120) DEFAULT 'daily_life',
    ADD COLUMN IF NOT EXISTS conversation_style VARCHAR(30) NOT NULL DEFAULT 'guided',
    ADD COLUMN IF NOT EXISTS conversation_max_turns INTEGER NOT NULL DEFAULT 10;

CREATE INDEX IF NOT EXISTS idx_students_status_created
ON students(status, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_student_profiles_last_study
ON student_profiles(last_study_at DESC NULLS LAST);

CREATE INDEX IF NOT EXISTS idx_student_profiles_diagnostic_status
ON student_profiles(diagnostic_status, diagnostic_step);

CREATE INDEX IF NOT EXISTS idx_messages_student_created
ON messages(student_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_sessions_student_created
ON sessions(student_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_sessions_status_mode_created
ON sessions(status, mode, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_student_activities_student_status
ON student_activities(student_id, status, assigned_at DESC);

CREATE INDEX IF NOT EXISTS idx_student_vocabulary_student_status_review
ON student_vocabulary(student_id, status, next_review_at);

CREATE INDEX IF NOT EXISTS idx_student_errors_student_status_review
ON student_errors(student_id, status, next_review_at);

CREATE INDEX IF NOT EXISTS idx_weekly_reports_student_week
ON weekly_reports(student_id, week_start DESC);

GRANT SELECT, INSERT, UPDATE, DELETE
ON student_preferences
TO rsenglish_app;
