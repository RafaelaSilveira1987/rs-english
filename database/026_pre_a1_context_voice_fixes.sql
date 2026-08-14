-- RS English v10.3
-- Compatibilidade completa com PRE-A1 e busca normalizada por telefone.
-- Execute após as migrations 024 e 025.

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
        ) AS columns_to_expand(table_name, column_name)
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

ALTER TABLE student_profiles
    ADD COLUMN IF NOT EXISTS preferred_language_support VARCHAR(30) NOT NULL DEFAULT 'adaptive',
    ADD COLUMN IF NOT EXISTS initial_self_assessment INTEGER,
    ADD COLUMN IF NOT EXISTS pre_a1 BOOLEAN NOT NULL DEFAULT FALSE;

UPDATE student_profiles
SET pre_a1 = TRUE
WHERE overall_level = 'PRE-A1'
   OR estimated_level = 'PRE-A1';

CREATE INDEX IF NOT EXISTS idx_students_phone_normalized
ON students (
    regexp_replace(COALESCE(phone, ''), '[^0-9]', '', 'g')
);

GRANT SELECT, INSERT, UPDATE, DELETE
ON student_profiles, diagnostic_reports, correction_events
TO rsenglish_app;
