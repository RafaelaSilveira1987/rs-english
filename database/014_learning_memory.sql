ALTER TABLE student_errors
    ADD COLUMN IF NOT EXISTS canonical_key VARCHAR(255),
    ADD COLUMN IF NOT EXISTS mastery_score NUMERIC(5,2) NOT NULL DEFAULT 0;

ALTER TABLE student_vocabulary
    ADD COLUMN IF NOT EXISTS interval_days INTEGER NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS ease_factor NUMERIC(4,2) NOT NULL DEFAULT 2.50;

UPDATE vocabulary
SET normalized_word = lower(trim(word))
WHERE normalized_word IS NULL OR normalized_word = '';

UPDATE student_errors
SET canonical_key = lower(regexp_replace(COALESCE(topic, category, 'other'), '[^a-zA-Z0-9_]+', '_', 'g'))
WHERE canonical_key IS NULL OR canonical_key = '';

CREATE INDEX IF NOT EXISTS idx_vocabulary_normalized_word_v4
ON vocabulary(normalized_word);

CREATE INDEX IF NOT EXISTS idx_student_error_active_topic_v4
ON student_errors(student_id, canonical_key, status);

CREATE INDEX IF NOT EXISTS idx_student_errors_priority
ON student_errors(student_id, status, occurrences DESC, mastery_score ASC);

CREATE INDEX IF NOT EXISTS idx_student_vocab_due
ON student_vocabulary(student_id, status, next_review_at);

GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO rsenglish_app;
GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO rsenglish_app;
