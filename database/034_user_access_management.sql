-- RS English v16 - Gestão de usuários e senhas
-- Execute após a migration 033_learning_telemetry.sql.

BEGIN;

ALTER TABLE app_users
    ADD COLUMN IF NOT EXISTS auth_version INTEGER NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS username_changed_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS password_reset_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS password_reset_by UUID REFERENCES app_users(id) ON DELETE SET NULL;

UPDATE app_users
SET auth_version = 1
WHERE auth_version IS NULL OR auth_version < 1;

CREATE INDEX IF NOT EXISTS idx_app_users_username_lower
ON app_users ((lower(username)));

CREATE INDEX IF NOT EXISTS idx_app_users_student_status
ON app_users (student_id, status)
WHERE role = 'student';

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'rsenglish_app') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON app_users TO rsenglish_app;
    END IF;
END $$;

COMMIT;
