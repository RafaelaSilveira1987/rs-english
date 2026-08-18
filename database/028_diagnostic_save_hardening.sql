-- RS English v10.5
-- Endurecimento do salvamento do diagnóstico e compatibilidade com PRE-A1.
-- Execute após as migrations 024, 025, 026 e 027.

CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- Amplia todas as colunas de nível usadas pelo diagnóstico.
DO $$
DECLARE
    item RECORD;
BEGIN
    FOR item IN
        SELECT *
        FROM (VALUES
            ('student_profiles', 'overall_level'),
            ('student_profiles', 'estimated_level'),
            ('sessions', 'level'),
            ('assessments', 'level'),
            ('assessment_results', 'overall_level'),
            ('study_plans', 'target_level'),
            ('activities', 'level'),
            ('vocabulary', 'level'),
            ('knowledge_chunks', 'level'),
            ('curriculum_modules', 'level'),
            ('diagnostic_reports', 'estimated_level')
        ) AS targets(table_name, column_name)
    LOOP
        IF EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = item.table_name
              AND column_name = item.column_name
        ) THEN
            EXECUTE format(
                'ALTER TABLE public.%I ALTER COLUMN %I TYPE VARCHAR(10)',
                item.table_name,
                item.column_name
            );
        END IF;
    END LOOP;
END
$$;

-- Colunas necessárias no perfil do aluno.
ALTER TABLE student_profiles
    ADD COLUMN IF NOT EXISTS diagnostic_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS diagnostic_step INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS diagnostic_started_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS diagnostic_completed_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS estimated_level VARCHAR(10),
    ADD COLUMN IF NOT EXISTS preferred_language_support VARCHAR(30) NOT NULL DEFAULT 'adaptive',
    ADD COLUMN IF NOT EXISTS initial_self_assessment INTEGER,
    ADD COLUMN IF NOT EXISTS pre_a1 BOOLEAN NOT NULL DEFAULT FALSE;

-- Compatibilidade para mensagens de voz transcritas.
ALTER TABLE messages
    ADD COLUMN IF NOT EXISTS transcription TEXT;

-- Tabelas auxiliares usadas ao concluir o diagnóstico.
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

CREATE TABLE IF NOT EXISTS study_plans (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    student_id UUID NOT NULL REFERENCES students(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    start_date DATE NOT NULL DEFAULT CURRENT_DATE,
    end_date DATE,
    goal TEXT,
    target_level VARCHAR(10),
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    plan_data JSONB NOT NULL DEFAULT '{}'::jsonb
);

CREATE INDEX IF NOT EXISTS idx_diagnostic_reports_student
    ON diagnostic_reports(student_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_correction_events_student
    ON correction_events(student_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_study_plans_student_status
    ON study_plans(student_id, status);

CREATE INDEX IF NOT EXISTS idx_students_phone_normalized
    ON students (regexp_replace(COALESCE(phone, ''), '[^0-9]', '', 'g'));

-- Remove somente CHECKs antigos de nível que rejeitam PRE-A1.
DO $$
DECLARE
    item RECORD;
BEGIN
    FOR item IN
        SELECT
            n.nspname AS schema_name,
            c.relname AS table_name,
            con.conname AS constraint_name,
            pg_get_constraintdef(con.oid) AS definition
        FROM pg_constraint con
        JOIN pg_class c ON c.oid = con.conrelid
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public'
          AND con.contype = 'c'
          AND c.relname IN (
              'student_profiles',
              'sessions',
              'assessments',
              'assessment_results',
              'study_plans'
          )
          AND pg_get_constraintdef(con.oid) ~* '(overall_level|estimated_level|target_level|level)'
          AND pg_get_constraintdef(con.oid) NOT LIKE '%PRE-A1%'
    LOOP
        EXECUTE format(
            'ALTER TABLE %I.%I DROP CONSTRAINT %I',
            item.schema_name,
            item.table_name,
            item.constraint_name
        );
    END LOOP;
END
$$;

-- Novos CHECKs aceitam PRE-A1 e são NOT VALID para não bloquear dados legados.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'student_profiles_overall_level_v105_check'
    ) THEN
        ALTER TABLE student_profiles
            ADD CONSTRAINT student_profiles_overall_level_v105_check
            CHECK (overall_level IS NULL OR overall_level IN ('PRE-A1','A1','A2','B1','B2','C1','C2'))
            NOT VALID;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'student_profiles_estimated_level_v105_check'
    ) THEN
        ALTER TABLE student_profiles
            ADD CONSTRAINT student_profiles_estimated_level_v105_check
            CHECK (estimated_level IS NULL OR estimated_level IN ('PRE-A1','A1','A2','B1','B2','C1','C2'))
            NOT VALID;
    END IF;
END
$$;

UPDATE student_profiles
SET pre_a1 = TRUE
WHERE overall_level = 'PRE-A1'
   OR estimated_level = 'PRE-A1';

-- Concede permissões somente quando a role existe.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'rsenglish_app') THEN
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON student_profiles, messages, sessions, diagnostic_reports, correction_events, study_plans TO rsenglish_app';
    END IF;
END
$$;
