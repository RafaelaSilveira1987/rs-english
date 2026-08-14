ALTER TABLE student_profiles
    ADD COLUMN IF NOT EXISTS preferred_language_support VARCHAR(30) NOT NULL DEFAULT 'adaptive',
    ADD COLUMN IF NOT EXISTS initial_self_assessment INTEGER,
    ADD COLUMN IF NOT EXISTS pre_a1 BOOLEAN NOT NULL DEFAULT FALSE;

ALTER TABLE student_preferences
    ADD COLUMN IF NOT EXISTS explanations_language VARCHAR(30) NOT NULL DEFAULT 'adaptive';

GRANT SELECT, INSERT, UPDATE, DELETE
ON student_profiles, student_preferences
TO rsenglish_app;
