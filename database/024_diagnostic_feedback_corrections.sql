CREATE TABLE IF NOT EXISTS diagnostic_reports (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    student_id UUID NOT NULL REFERENCES students(id) ON DELETE CASCADE,
    estimated_level VARCHAR(10) NOT NULL,
    confidence_score NUMERIC(5,2),
    strengths JSONB NOT NULL DEFAULT '[]'::jsonb,
    weaknesses JSONB NOT NULL DEFAULT '[]'::jsonb,
    detected_goals JSONB NOT NULL DEFAULT '[]'::jsonb,
    written_feedback TEXT NOT NULL,
    study_plan JSONB NOT NULL DEFAULT '{}'::jsonb,
    first_activity JSONB NOT NULL DEFAULT '{}'::jsonb,
    delivered_at TIMESTAMPTZ,
    delivery_channel VARCHAR(30),
    delivery_message_id VARCHAR(255),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS correction_events (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    student_id UUID NOT NULL REFERENCES students(id) ON DELETE CASCADE,
    session_id UUID REFERENCES sessions(id) ON DELETE SET NULL,
    channel VARCHAR(30) NOT NULL,
    correction_type VARCHAR(30) NOT NULL,
    original_text TEXT,
    corrected_text TEXT,
    explanation TEXT,
    target_word VARCHAR(255),
    detected_word VARCHAR(255),
    confidence_score NUMERIC(5,2),
    accepted BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS pronunciation_attempts (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    student_id UUID NOT NULL REFERENCES students(id) ON DELETE CASCADE,
    target_text TEXT NOT NULL,
    transcription TEXT NOT NULL,
    similarity_score NUMERIC(5,2) NOT NULL DEFAULT 0,
    matched_words JSONB NOT NULL DEFAULT '[]'::jsonb,
    missing_words JSONB NOT NULL DEFAULT '[]'::jsonb,
    unexpected_words JSONB NOT NULL DEFAULT '[]'::jsonb,
    audio_mime VARCHAR(120),
    audio_duration_seconds NUMERIC(10,2),
    feedback TEXT,
    model_audio_path TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_diagnostic_reports_student ON diagnostic_reports(student_id,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_correction_events_student ON correction_events(student_id,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_pronunciation_attempts_student ON pronunciation_attempts(student_id,created_at DESC);
GRANT SELECT, INSERT, UPDATE, DELETE ON diagnostic_reports, correction_events, pronunciation_attempts TO rsenglish_app;
