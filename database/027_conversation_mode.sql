-- RS English v10.4 - Modo de conversação guiada

ALTER TABLE sessions
    ADD COLUMN IF NOT EXISTS turn_count INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS max_turns INTEGER NOT NULL DEFAULT 10,
    ADD COLUMN IF NOT EXISTS conversation_topic VARCHAR(120),
    ADD COLUMN IF NOT EXISTS conversation_style VARCHAR(30) NOT NULL DEFAULT 'guided',
    ADD COLUMN IF NOT EXISTS conversation_summary TEXT,
    ADD COLUMN IF NOT EXISTS summary_data JSONB NOT NULL DEFAULT '{}'::jsonb,
    ADD COLUMN IF NOT EXISTS completed_reason VARCHAR(40),
    ADD COLUMN IF NOT EXISTS last_student_message_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS last_teacher_message_at TIMESTAMPTZ;

ALTER TABLE sessions
    DROP CONSTRAINT IF EXISTS sessions_turn_count_check,
    ADD CONSTRAINT sessions_turn_count_check
        CHECK (turn_count >= 0);

ALTER TABLE sessions
    DROP CONSTRAINT IF EXISTS sessions_max_turns_check,
    ADD CONSTRAINT sessions_max_turns_check
        CHECK (max_turns BETWEEN 4 AND 30);

ALTER TABLE sessions
    DROP CONSTRAINT IF EXISTS sessions_conversation_style_check,
    ADD CONSTRAINT sessions_conversation_style_check
        CHECK (conversation_style IN ('guided', 'free', 'roleplay'));

ALTER TABLE student_preferences
    ADD COLUMN IF NOT EXISTS conversation_topic VARCHAR(120) DEFAULT 'daily_life',
    ADD COLUMN IF NOT EXISTS conversation_style VARCHAR(30) NOT NULL DEFAULT 'guided',
    ADD COLUMN IF NOT EXISTS conversation_max_turns INTEGER NOT NULL DEFAULT 10;

ALTER TABLE student_preferences
    DROP CONSTRAINT IF EXISTS student_preferences_conversation_style_check,
    ADD CONSTRAINT student_preferences_conversation_style_check
        CHECK (conversation_style IN ('guided', 'free', 'roleplay'));

ALTER TABLE student_preferences
    DROP CONSTRAINT IF EXISTS student_preferences_conversation_max_turns_check,
    ADD CONSTRAINT student_preferences_conversation_max_turns_check
        CHECK (conversation_max_turns BETWEEN 4 AND 30);

CREATE INDEX IF NOT EXISTS idx_sessions_active_conversation
ON sessions(student_id, status, mode, created_at DESC)
WHERE status = 'active' AND mode = 'conversation';

CREATE INDEX IF NOT EXISTS idx_sessions_conversation_topic
ON sessions(student_id, conversation_topic, created_at DESC)
WHERE mode = 'conversation';

GRANT SELECT, INSERT, UPDATE, DELETE
ON sessions, student_preferences
TO rsenglish_app;
