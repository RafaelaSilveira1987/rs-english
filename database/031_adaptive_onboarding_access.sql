-- RS English v12 - Acolhimento adaptativo + acesso automático do aluno
-- Execute após a migration 030_student_portal_complete.sql.

ALTER TABLE student_profiles
    ADD COLUMN IF NOT EXISTS support_mode VARCHAR(30) NOT NULL DEFAULT 'pt_first',
    ADD COLUMN IF NOT EXISTS teaching_mode VARCHAR(40) NOT NULL DEFAULT 'foundations',
    ADD COLUMN IF NOT EXISTS preferred_explanation_language VARCHAR(10) NOT NULL DEFAULT 'pt-BR',
    ADD COLUMN IF NOT EXISTS diagnostic_confidence NUMERIC(5,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS onboarding_completed_at TIMESTAMPTZ;

ALTER TABLE app_users
    ADD COLUMN IF NOT EXISTS access_origin VARCHAR(40) NOT NULL DEFAULT 'manual',
    ADD COLUMN IF NOT EXISTS activated_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS first_access_at TIMESTAMPTZ;

UPDATE app_users
SET activated_at = COALESCE(activated_at, created_at)
WHERE status = 'active';

CREATE TABLE IF NOT EXISTS account_activation_tokens (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NOT NULL REFERENCES app_users(id) ON DELETE CASCADE,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    expires_at TIMESTAMPTZ NOT NULL,
    used_at TIMESTAMPTZ,
    delivery_channel VARCHAR(30) NOT NULL DEFAULT 'whatsapp',
    requested_from VARCHAR(50) NOT NULL DEFAULT 'automatic',
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_account_activation_user_created
ON account_activation_tokens(user_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_account_activation_valid
ON account_activation_tokens(user_id, expires_at)
WHERE used_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_app_users_student_role
ON app_users(student_id, role);

UPDATE student_profiles
SET
    support_mode = CASE
        WHEN COALESCE(overall_level, estimated_level, 'PRE-A1') IN ('PRE-A1', 'A1') THEN 'pt_first'
        WHEN COALESCE(overall_level, estimated_level, 'PRE-A1') = 'A2' THEN 'bilingual'
        WHEN COALESCE(overall_level, estimated_level, 'PRE-A1') IN ('B1', 'B2') THEN 'english_first'
        ELSE 'english_only'
    END,
    teaching_mode = CASE
        WHEN COALESCE(overall_level, estimated_level, 'PRE-A1') = 'PRE-A1' THEN 'foundations'
        WHEN COALESCE(overall_level, estimated_level, 'PRE-A1') = 'A1' THEN 'guided'
        WHEN COALESCE(overall_level, estimated_level, 'PRE-A1') = 'A2' THEN 'guided_conversation'
        WHEN COALESCE(overall_level, estimated_level, 'PRE-A1') IN ('B1', 'B2') THEN 'conversation'
        ELSE 'immersion'
    END,
    preferred_explanation_language = CASE
        WHEN COALESCE(overall_level, estimated_level, 'PRE-A1') IN ('PRE-A1', 'A1', 'A2') THEN 'pt-BR'
        ELSE 'adaptive'
    END
WHERE initial_self_assessment IS NULL
  AND diagnostic_status = 'completed'
  AND support_mode = 'pt_first'
  AND teaching_mode = 'foundations';

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'rsenglish_app') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON account_activation_tokens TO rsenglish_app;
        GRANT SELECT, INSERT, UPDATE, DELETE ON app_users, student_profiles TO rsenglish_app;
    END IF;
END $$;
