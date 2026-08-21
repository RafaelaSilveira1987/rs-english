-- RS English v18 — Ciclo de aprendizagem do portal do aluno
-- Execute após 034_user_access_management.sql.

BEGIN;

ALTER TABLE weekly_goals
    ADD COLUMN IF NOT EXISTS target_source VARCHAR(30) NOT NULL DEFAULT 'profile';

ALTER TABLE student_activities
    ADD COLUMN IF NOT EXISTS study_plan_id UUID REFERENCES study_plans(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS plan_week SMALLINT,
    ADD COLUMN IF NOT EXISTS plan_item_index SMALLINT,
    ADD COLUMN IF NOT EXISTS available_from DATE,
    ADD COLUMN IF NOT EXISTS due_date DATE,
    ADD COLUMN IF NOT EXISTS assignment_source VARCHAR(30) NOT NULL DEFAULT 'system';

ALTER TABLE voice_conversations
    ADD COLUMN IF NOT EXISTS source_message_id UUID REFERENCES messages(id) ON DELETE SET NULL;

ALTER TABLE student_vocabulary
    ADD COLUMN IF NOT EXISTS last_seen_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS source VARCHAR(30) NOT NULL DEFAULT 'conversation',
    ADD COLUMN IF NOT EXISTS source_context JSONB NOT NULL DEFAULT '{}'::jsonb;

CREATE UNIQUE INDEX IF NOT EXISTS uq_student_activity_plan_item
ON student_activities(student_id, study_plan_id, plan_week, plan_item_index)
WHERE study_plan_id IS NOT NULL AND plan_week IS NOT NULL AND plan_item_index IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_student_activities_plan_availability
ON student_activities(student_id, status, available_from, plan_week);

CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_conversations_source_message
ON voice_conversations(source_message_id)
WHERE source_message_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_student_vocabulary_last_seen
ON student_vocabulary(student_id, last_seen_at DESC);

-- Corrige feedbacks antigos que armazenaram barras invertidas em vez de quebras de linha.
UPDATE diagnostic_reports
SET written_feedback = replace(
        replace(
            replace(written_feedback, E'\\\\r\\\\n', E'\n'),
            E'\\\\n',
            E'\n'
        ),
        E'\\\\r',
        E'\n'
    )
WHERE written_feedback LIKE '%\\n%' OR written_feedback LIKE '%\\r%';

UPDATE messages
SET content = replace(
        replace(
            replace(content, E'\\\\r\\\\n', E'\n'),
            E'\\\\n',
            E'\n'
        ),
        E'\\\\r',
        E'\n'
    )
WHERE content IS NOT NULL
  AND (content LIKE '%\\n%' OR content LIKE '%\\r%');

-- Recupera áudios antigos do WhatsApp que estavam apenas em messages.
INSERT INTO voice_conversations(
    student_id,
    channel,
    student_audio_mime,
    student_audio_duration_seconds,
    student_transcription,
    teacher_text,
    session_id,
    status,
    source_message_id,
    created_at
)
SELECT
    m.student_id,
    CASE
        WHEN COALESCE(s.channel, '') LIKE 'web%' THEN 'web_voice'
        ELSE 'whatsapp_voice'
    END,
    NULL,
    NULL,
    COALESCE(NULLIF(m.transcription, ''), NULLIF(m.content, ''), 'Áudio recebido'),
    (
        SELECT tm.content
        FROM messages tm
        WHERE tm.session_id = m.session_id
          AND tm.role = 'teacher'
          AND tm.created_at >= m.created_at
        ORDER BY tm.created_at
        LIMIT 1
    ),
    m.session_id,
    'completed',
    m.id,
    m.created_at
FROM messages m
LEFT JOIN sessions s ON s.id = m.session_id
WHERE m.role = 'student'
  AND m.message_type = 'audio'
  AND NOT EXISTS (
      SELECT 1
      FROM voice_conversations vc
      WHERE vc.source_message_id = m.id
         OR (
             vc.student_id = m.student_id
             AND vc.session_id IS NOT DISTINCT FROM m.session_id
             AND vc.created_at BETWEEN m.created_at - INTERVAL '5 minutes' AND m.created_at + INTERVAL '5 minutes'
             AND COALESCE(vc.student_transcription, '') = COALESCE(NULLIF(m.transcription, ''), NULLIF(m.content, ''), '')
         )
  )
ON CONFLICT (source_message_id) WHERE source_message_id IS NOT NULL DO NOTHING;

INSERT INTO achievements(code, title, description, xp_reward)
VALUES
    ('DIAGNOSTIC_COMPLETE', 'Diagnóstico concluído', 'Concluiu o diagnóstico adaptativo inicial.', 30),
    ('FIRST_CONVERSATION', 'Primeira conversa', 'Registrou a primeira conversa de aprendizagem com a Emma.', 15),
    ('FIRST_VOICE', 'Primeiro áudio', 'Praticou inglês por áudio pela primeira vez.', 20),
    ('STUDY_60', 'Primeira hora', 'Acumulou 60 minutos reais de estudo.', 30),
    ('STUDY_300', 'Cinco horas de prática', 'Acumulou 300 minutos reais de estudo.', 80)
ON CONFLICT(code) DO UPDATE SET
    title = EXCLUDED.title,
    description = EXCLUDED.description,
    xp_reward = EXCLUDED.xp_reward,
    active = TRUE;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'rsenglish_app') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON weekly_goals, student_activities,
            voice_conversations, student_vocabulary, achievements, student_achievements
        TO rsenglish_app;
    END IF;
END $$;

COMMIT;
