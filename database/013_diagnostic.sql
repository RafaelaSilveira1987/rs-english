ALTER TABLE student_profiles
    ADD COLUMN IF NOT EXISTS diagnostic_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS diagnostic_step INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS diagnostic_started_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS diagnostic_completed_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS estimated_level VARCHAR(5);

CREATE TABLE IF NOT EXISTS study_plans (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    student_id UUID NOT NULL REFERENCES students(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    start_date DATE NOT NULL DEFAULT CURRENT_DATE,
    end_date DATE,
    goal TEXT,
    target_level VARCHAR(5),
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    plan_data JSONB NOT NULL DEFAULT '{}'::jsonb
);

CREATE INDEX IF NOT EXISTS idx_profiles_diagnostic
ON student_profiles(diagnostic_status, diagnostic_step);

CREATE INDEX IF NOT EXISTS idx_study_plans_student_status
ON study_plans(student_id, status);

GRANT SELECT, INSERT, UPDATE, DELETE ON study_plans TO rsenglish_app;
