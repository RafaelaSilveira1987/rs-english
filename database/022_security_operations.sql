CREATE TABLE IF NOT EXISTS password_reset_tokens (
 id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
 user_id UUID NOT NULL REFERENCES app_users(id) ON DELETE CASCADE,
 token_hash VARCHAR(255) NOT NULL UNIQUE,
 expires_at TIMESTAMPTZ NOT NULL,
 used_at TIMESTAMPTZ,
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS login_attempts (
 id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
 login_identifier VARCHAR(255),
 ip_address VARCHAR(80),
 success BOOLEAN NOT NULL DEFAULT FALSE,
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS system_events (
 id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
 event_type VARCHAR(100) NOT NULL,
 severity VARCHAR(20) NOT NULL DEFAULT 'info',
 message TEXT NOT NULL,
 metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
ALTER TABLE app_users
 ADD COLUMN IF NOT EXISTS password_changed_at TIMESTAMPTZ,
 ADD COLUMN IF NOT EXISTS failed_login_count INTEGER NOT NULL DEFAULT 0,
 ADD COLUMN IF NOT EXISTS locked_until TIMESTAMPTZ,
 ADD COLUMN IF NOT EXISTS must_change_password BOOLEAN NOT NULL DEFAULT FALSE;
CREATE INDEX IF NOT EXISTS idx_password_reset_tokens_user ON password_reset_tokens(user_id,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_login_attempts_identifier ON login_attempts(login_identifier,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts(ip_address,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_system_events_created ON system_events(created_at DESC);
GRANT SELECT,INSERT,UPDATE,DELETE ON password_reset_tokens,login_attempts,system_events TO rsenglish_app;
