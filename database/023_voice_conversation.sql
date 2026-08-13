-- RS English v10 - Conversação por voz

CREATE TABLE IF NOT EXISTS voice_conversations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    student_id UUID NOT NULL REFERENCES students(id) ON DELETE CASCADE,

    channel VARCHAR(30) NOT NULL,
    direction VARCHAR(20) NOT NULL DEFAULT 'roundtrip',

    student_audio_path TEXT,
    student_audio_mime VARCHAR(120),
    student_audio_duration_seconds NUMERIC(10,2),
    student_transcription TEXT,

    teacher_text TEXT,
    teacher_audio_path TEXT,
    teacher_voice VARCHAR(50),
    teacher_audio_format VARCHAR(20) NOT NULL DEFAULT 'mp3',

    session_id UUID REFERENCES sessions(id) ON DELETE SET NULL,

    status VARCHAR(30) NOT NULL DEFAULT 'completed',
    error_message TEXT,

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE student_preferences
    ADD COLUMN IF NOT EXISTS response_mode VARCHAR(30) NOT NULL DEFAULT 'automatic',
    ADD COLUMN IF NOT EXISTS voice_name VARCHAR(50) NOT NULL DEFAULT 'coral',
    ADD COLUMN IF NOT EXISTS voice_speed NUMERIC(4,2) NOT NULL DEFAULT 1.00,
    ADD COLUMN IF NOT EXISTS autoplay_audio BOOLEAN NOT NULL DEFAULT TRUE,
    ADD COLUMN IF NOT EXISTS show_transcription BOOLEAN NOT NULL DEFAULT TRUE;

CREATE INDEX IF NOT EXISTS idx_voice_conversations_student_created
ON voice_conversations(student_id,created_at DESC);

CREATE INDEX IF NOT EXISTS idx_voice_conversations_channel_created
ON voice_conversations(channel,created_at DESC);

GRANT SELECT, INSERT, UPDATE, DELETE
ON voice_conversations
TO rsenglish_app;
