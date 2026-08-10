-- Índices úteis para a API/painel

CREATE INDEX IF NOT EXISTS idx_students_status
ON students(status);

CREATE INDEX IF NOT EXISTS idx_profiles_level
ON student_profiles(overall_level);

CREATE INDEX IF NOT EXISTS idx_sessions_student_created
ON sessions(student_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_errors_student_created
ON student_errors(student_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_errors_student_status
ON student_errors(student_id, status);

CREATE INDEX IF NOT EXISTS idx_vocabulary_normalized
ON vocabulary(normalized_word);

CREATE INDEX IF NOT EXISTS idx_student_vocab_status
ON student_vocabulary(student_id, status);
