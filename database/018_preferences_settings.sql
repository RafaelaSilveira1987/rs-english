-- RS English v7 - Preferências pedagógicas e configurações do professor

CREATE TABLE IF NOT EXISTS teacher_settings (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    organization_id UUID
        REFERENCES organizations(id)
        ON DELETE CASCADE,

    teacher_name VARCHAR(120) NOT NULL DEFAULT 'Emma',
    teacher_personality VARCHAR(50) NOT NULL DEFAULT 'balanced',
    default_correction_mode VARCHAR(30) NOT NULL DEFAULT 'balanced',

    default_language_mix VARCHAR(30) NOT NULL DEFAULT 'adaptive',

    max_corrections_per_message INTEGER NOT NULL DEFAULT 2,

    teacher_rules JSONB NOT NULL DEFAULT '{}'::jsonb,

    active BOOLEAN NOT NULL DEFAULT TRUE,

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS student_preferences (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    student_id UUID NOT NULL UNIQUE
        REFERENCES students(id)
        ON DELETE CASCADE,

    daily_minutes INTEGER NOT NULL DEFAULT 20,
    weekly_days INTEGER NOT NULL DEFAULT 5,

    preferred_topics JSONB NOT NULL DEFAULT '[]'::jsonb,
    avoided_topics JSONB NOT NULL DEFAULT '[]'::jsonb,

    focus_mode VARCHAR(30) NOT NULL DEFAULT 'conversation',
    correction_mode VARCHAR(30) NOT NULL DEFAULT 'balanced',

    preferred_study_time VARCHAR(30),
    notes TEXT,

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

GRANT SELECT, INSERT, UPDATE, DELETE
ON teacher_settings, student_preferences
TO rsenglish_app;
