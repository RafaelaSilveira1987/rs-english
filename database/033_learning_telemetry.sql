-- RS English v15 - Telemetria pedagógica e integração completa de progresso
-- Execute após a migration 032_real_progress.sql.
-- A migration é idempotente e pode ser executada novamente com segurança.

BEGIN;

CREATE TABLE IF NOT EXISTS student_skill_evidence (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    student_id UUID NOT NULL REFERENCES students(id) ON DELETE CASCADE,
    session_id UUID REFERENCES sessions(id) ON DELETE SET NULL,
    student_activity_id UUID REFERENCES student_activities(id) ON DELETE SET NULL,
    event_key VARCHAR(220) NOT NULL UNIQUE,
    source VARCHAR(40) NOT NULL,
    skill_code VARCHAR(40) NOT NULL,
    score NUMERIC(5,2) NOT NULL CHECK (score >= 0 AND score <= 100),
    weight NUMERIC(6,2) NOT NULL DEFAULT 1.00 CHECK (weight > 0),
    confidence NUMERIC(5,2) CHECK (confidence IS NULL OR (confidence >= 0 AND confidence <= 100)),
    evidence_text TEXT,
    evidence_data JSONB NOT NULL DEFAULT '{}'::jsonb,
    observed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT student_skill_evidence_skill_check CHECK (
        skill_code IN (
            'grammar','vocabulary','speaking','listening',
            'reading','writing','fluency','pronunciation'
        )
    )
);

CREATE INDEX IF NOT EXISTS idx_skill_evidence_student_skill_date
    ON student_skill_evidence(student_id, skill_code, observed_at DESC);

CREATE INDEX IF NOT EXISTS idx_skill_evidence_source_date
    ON student_skill_evidence(source, observed_at DESC);

CREATE TABLE IF NOT EXISTS student_learning_events (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    student_id UUID NOT NULL REFERENCES students(id) ON DELETE CASCADE,
    session_id UUID REFERENCES sessions(id) ON DELETE SET NULL,
    event_key VARCHAR(220) NOT NULL UNIQUE,
    event_type VARCHAR(50) NOT NULL,
    channel VARCHAR(40) NOT NULL DEFAULT 'system',
    source_id UUID,
    duration_seconds INTEGER NOT NULL DEFAULT 0 CHECK (duration_seconds >= 0),
    score NUMERIC(5,2) CHECK (score IS NULL OR (score >= 0 AND score <= 100)),
    xp_earned INTEGER NOT NULL DEFAULT 0,
    event_data JSONB NOT NULL DEFAULT '{}'::jsonb,
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_learning_events_student_date
    ON student_learning_events(student_id, occurred_at DESC);

CREATE INDEX IF NOT EXISTS idx_learning_events_type_date
    ON student_learning_events(event_type, occurred_at DESC);

CREATE INDEX IF NOT EXISTS idx_learning_events_channel_date
    ON student_learning_events(channel, occurred_at DESC);

ALTER TABLE activity_attempts
    ADD COLUMN IF NOT EXISTS skill_code VARCHAR(40),
    ADD COLUMN IF NOT EXISTS difficulty VARCHAR(30),
    ADD COLUMN IF NOT EXISTS duration_seconds INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS correct_answers INTEGER,
    ADD COLUMN IF NOT EXISTS total_questions INTEGER,
    ADD COLUMN IF NOT EXISTS source VARCHAR(30) NOT NULL DEFAULT 'web';

ALTER TABLE correction_events
    ADD COLUMN IF NOT EXISTS event_key VARCHAR(220),
    ADD COLUMN IF NOT EXISTS category VARCHAR(80),
    ADD COLUMN IF NOT EXISTS canonical_key VARCHAR(180),
    ADD COLUMN IF NOT EXISTS occurrences INTEGER NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS status VARCHAR(30) NOT NULL DEFAULT 'learning',
    ADD COLUMN IF NOT EXISTS resolved_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

ALTER TABLE student_errors
    ADD COLUMN IF NOT EXISTS source_channel VARCHAR(40) NOT NULL DEFAULT 'unknown',
    ADD COLUMN IF NOT EXISTS first_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    ADD COLUMN IF NOT EXISTS last_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    ADD COLUMN IF NOT EXISTS resolved_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS resolution_note TEXT;

ALTER TABLE student_profiles
    ADD COLUMN IF NOT EXISTS last_skill_evaluation_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS progress_updated_at TIMESTAMPTZ;

ALTER TABLE student_progress_snapshots
    ADD COLUMN IF NOT EXISTS active_days_30d INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS study_minutes_total INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS recurring_errors INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS corrections_resolved_rate NUMERIC(5,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS skill_evidence_count INTEGER NOT NULL DEFAULT 0;

CREATE UNIQUE INDEX IF NOT EXISTS uq_correction_events_event_key
    ON correction_events(event_key)
    WHERE event_key IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_correction_events_student_canonical
    ON correction_events(student_id, canonical_key, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_student_errors_attention
    ON student_errors(student_id, status, occurrences DESC, last_seen_at DESC);

-- Registra como evidência-base os escores que já existiam antes da v15.
INSERT INTO student_skill_evidence(
    student_id, event_key, source, skill_code, score, weight,
    evidence_text, observed_at
)
SELECT
    sp.student_id,
    'baseline:' || sp.student_id::text || ':' || skill.skill_code,
    'migration_baseline',
    skill.skill_code,
    skill.score,
    1.00,
    'Valor existente no perfil antes da implantação da v15.',
    COALESCE(sp.diagnostic_completed_at, sp.updated_at, NOW())
FROM student_profiles sp
CROSS JOIN LATERAL (
    VALUES
        ('grammar', COALESCE(sp.grammar_score, 0)::numeric),
        ('vocabulary', COALESCE(sp.vocabulary_score, 0)::numeric),
        ('speaking', COALESCE(sp.speaking_score, 0)::numeric),
        ('listening', COALESCE(sp.listening_score, 0)::numeric),
        ('reading', COALESCE(sp.reading_score, 0)::numeric),
        ('writing', COALESCE(sp.writing_score, 0)::numeric),
        ('fluency', COALESCE(sp.fluency_score, 0)::numeric),
        ('pronunciation', COALESCE(sp.pronunciation_score, 0)::numeric)
) AS skill(skill_code, score)
WHERE skill.score > 0
ON CONFLICT(event_key) DO NOTHING;

-- Recupera medições históricas das sessões, preservando o que já foi avaliado.
INSERT INTO student_skill_evidence(
    student_id, session_id, event_key, source, skill_code, score,
    weight, evidence_text, observed_at
)
SELECT
    s.student_id,
    s.id,
    'historical-session:' || s.id::text || ':' || skill.skill_code,
    CASE WHEN s.mode = 'assessment' THEN 'historical_diagnostic_session' ELSE 'historical_conversation_session' END,
    skill.skill_code,
    skill.score,
    CASE WHEN s.mode = 'assessment' THEN 3.00 ELSE 1.50 END,
    'Medição recuperada de uma sessão anterior à v15.',
    COALESCE(s.ended_at, s.created_at, NOW())
FROM sessions s
CROSS JOIN LATERAL (
    VALUES
        ('grammar', s.grammar_score::numeric),
        ('vocabulary', s.vocabulary_score::numeric),
        ('fluency', s.fluency_score::numeric),
        ('listening', s.comprehension_score::numeric),
        ('reading', s.comprehension_score::numeric)
) AS skill(skill_code, score)
WHERE skill.score IS NOT NULL
  AND skill.score >= 0
  AND skill.score <= 100
ON CONFLICT(event_key) DO NOTHING;

-- Recupera a competência principal das atividades já concluídas.
INSERT INTO student_skill_evidence(
    student_id, student_activity_id, event_key, source, skill_code,
    score, weight, evidence_text, observed_at
)
SELECT
    sa.student_id,
    sa.id,
    'historical-activity:' || sa.id::text || ':' || normalized.skill_code,
    'historical_activity',
    normalized.skill_code,
    sa.score,
    2.00,
    'Nota recuperada de uma atividade concluída antes da v15.',
    COALESCE(sa.completed_at, sa.assigned_at, a.created_at, NOW())
FROM student_activities sa
JOIN activities a ON a.id = sa.activity_id
CROSS JOIN LATERAL (
    SELECT CASE
        WHEN lower(COALESCE(a.skill, '')) IN ('grammar','gramatica','gramática') THEN 'grammar'
        WHEN lower(COALESCE(a.skill, '')) IN ('vocabulary','vocab','vocabulario','vocabulário') THEN 'vocabulary'
        WHEN lower(COALESCE(a.skill, '')) IN ('speaking','interaction','interacao','interação') THEN 'speaking'
        WHEN lower(COALESCE(a.skill, '')) IN ('listening','comprehension','compreensao','compreensão') THEN 'listening'
        WHEN lower(COALESCE(a.skill, '')) IN ('reading','leitura') THEN 'reading'
        WHEN lower(COALESCE(a.skill, '')) IN ('writing','escrita') THEN 'writing'
        WHEN lower(COALESCE(a.skill, '')) IN ('fluency','fluencia','fluência') THEN 'fluency'
        WHEN lower(COALESCE(a.skill, '')) IN ('pronunciation','pronuncia','pronúncia') THEN 'pronunciation'
        ELSE NULL
    END AS skill_code
) normalized
WHERE sa.status = 'completed'
  AND sa.score IS NOT NULL
  AND sa.score >= 0
  AND sa.score <= 100
  AND normalized.skill_code IS NOT NULL
ON CONFLICT(event_key) DO NOTHING;

-- Recupera tentativas antigas de pronúncia.
INSERT INTO student_skill_evidence(
    student_id, event_key, source, skill_code, score, weight,
    evidence_text, observed_at
)
SELECT
    pa.student_id,
    'historical-pronunciation:' || pa.id::text,
    'historical_pronunciation',
    'pronunciation',
    pa.similarity_score,
    2.50,
    COALESCE(NULLIF(pa.feedback, ''), 'Tentativa de pronúncia anterior à v15.'),
    pa.created_at
FROM pronunciation_attempts pa
WHERE pa.similarity_score IS NOT NULL
  AND pa.similarity_score >= 0
  AND pa.similarity_score <= 100
ON CONFLICT(event_key) DO NOTHING;

-- Consolida eventos históricos já existentes para que o painel não comece vazio.
INSERT INTO student_learning_events(
    student_id, session_id, event_key, event_type, channel,
    source_id, duration_seconds, score, xp_earned, event_data, occurred_at
)
SELECT
    s.student_id,
    s.id,
    'session:' || s.id::text,
    CASE WHEN s.mode = 'assessment' THEN 'diagnostic_session' ELSE 'conversation_session' END,
    COALESCE(NULLIF(s.channel, ''), 'unknown'),
    s.id,
    0,
    NULL,
    0,
    jsonb_build_object(
        'mode', s.mode,
        'status', s.status,
        'topic', COALESCE(s.conversation_topic, s.topic),
        'turn_count', COALESCE(s.turn_count, 0)
    ),
    s.created_at
FROM sessions s
ON CONFLICT(event_key) DO NOTHING;

INSERT INTO student_learning_events(
    student_id, event_key, event_type, channel, source_id,
    duration_seconds, score, xp_earned, event_data, occurred_at
)
SELECT
    sa.student_id,
    'activity:' || sa.id::text,
    'activity_completed',
    'platform',
    sa.id,
    COALESCE(a.estimated_minutes, 0) * 60,
    sa.score,
    COALESCE(sa.xp_earned, 0),
    jsonb_build_object(
        'activity_id', a.id,
        'title', a.title,
        'skill', a.skill,
        'level', a.level
    ),
    COALESCE(sa.completed_at, sa.assigned_at, a.created_at, NOW())
FROM student_activities sa
JOIN activities a ON a.id = sa.activity_id
WHERE sa.status = 'completed'
ON CONFLICT(event_key) DO NOTHING;

INSERT INTO student_learning_events(
    student_id, session_id, event_key, event_type, channel,
    source_id, duration_seconds, event_data, occurred_at
)
SELECT
    vc.student_id,
    vc.session_id,
    'voice:' || vc.id::text,
    'voice_practice',
    COALESCE(NULLIF(vc.channel, ''), 'voice'),
    vc.id,
    GREATEST(0, ROUND(COALESCE(vc.student_audio_duration_seconds, 0))::integer),
    jsonb_build_object('status', vc.status),
    vc.created_at
FROM voice_conversations vc
WHERE vc.status = 'completed'
ON CONFLICT(event_key) DO NOTHING;

INSERT INTO student_learning_events(
    student_id, event_key, event_type, channel, source_id,
    duration_seconds, score, event_data, occurred_at
)
SELECT
    dr.student_id,
    'diagnostic:' || dr.id::text,
    'diagnostic_completed',
    COALESCE(NULLIF(dr.delivery_channel, ''), 'system'),
    dr.id,
    0,
    dr.confidence_score,
    jsonb_build_object('estimated_level', dr.estimated_level),
    dr.created_at
FROM diagnostic_reports dr
ON CONFLICT(event_key) DO NOTHING;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'rsenglish_app') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON student_skill_evidence TO rsenglish_app;
        GRANT SELECT, INSERT, UPDATE, DELETE ON student_learning_events TO rsenglish_app;
        GRANT SELECT, INSERT, UPDATE, DELETE ON activity_attempts TO rsenglish_app;
        GRANT SELECT, INSERT, UPDATE, DELETE ON correction_events TO rsenglish_app;
        GRANT SELECT, INSERT, UPDATE, DELETE ON student_errors TO rsenglish_app;
        GRANT SELECT, INSERT, UPDATE, DELETE ON student_progress_snapshots TO rsenglish_app;
    END IF;
END $$;

COMMIT;
