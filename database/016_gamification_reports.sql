CREATE TABLE IF NOT EXISTS weekly_goals (
 id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
 student_id UUID NOT NULL REFERENCES students(id) ON DELETE CASCADE,
 week_start DATE NOT NULL,
 week_end DATE NOT NULL,
 target_minutes INTEGER NOT NULL DEFAULT 100,
 target_activities INTEGER NOT NULL DEFAULT 4,
 target_words INTEGER NOT NULL DEFAULT 20,
 completed_minutes INTEGER NOT NULL DEFAULT 0,
 completed_activities INTEGER NOT NULL DEFAULT 0,
 learned_words INTEGER NOT NULL DEFAULT 0,
 status VARCHAR(30) NOT NULL DEFAULT 'active',
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
 updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
 UNIQUE(student_id, week_start)
);

CREATE TABLE IF NOT EXISTS achievements (
 id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
 code VARCHAR(100) UNIQUE NOT NULL,
 title VARCHAR(150) NOT NULL,
 description TEXT,
 xp_reward INTEGER NOT NULL DEFAULT 0,
 active BOOLEAN NOT NULL DEFAULT TRUE,
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS student_achievements (
 id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
 student_id UUID NOT NULL REFERENCES students(id) ON DELETE CASCADE,
 achievement_id UUID NOT NULL REFERENCES achievements(id) ON DELETE CASCADE,
 earned_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
 UNIQUE(student_id, achievement_id)
);

CREATE TABLE IF NOT EXISTS weekly_reports (
 id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
 student_id UUID NOT NULL REFERENCES students(id) ON DELETE CASCADE,
 week_start DATE NOT NULL,
 week_end DATE NOT NULL,
 report_data JSONB NOT NULL DEFAULT '{}'::jsonb,
 teacher_summary TEXT,
 status VARCHAR(30) NOT NULL DEFAULT 'generated',
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
 UNIQUE(student_id, week_start)
);

ALTER TABLE activities ADD COLUMN IF NOT EXISTS xp_reward INTEGER NOT NULL DEFAULT 10;
ALTER TABLE activities ADD COLUMN IF NOT EXISTS estimated_minutes INTEGER NOT NULL DEFAULT 10;
ALTER TABLE activities ADD COLUMN IF NOT EXISTS generated_by VARCHAR(30) NOT NULL DEFAULT 'system';
ALTER TABLE student_activities ADD COLUMN IF NOT EXISTS xp_earned INTEGER NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS idx_weekly_goals_student_week ON weekly_goals(student_id, week_start DESC);
CREATE INDEX IF NOT EXISTS idx_student_activities_pending ON student_activities(student_id, status, assigned_at);
CREATE INDEX IF NOT EXISTS idx_weekly_reports_student_week ON weekly_reports(student_id, week_start DESC);

GRANT SELECT, INSERT, UPDATE, DELETE ON weekly_goals, achievements, student_achievements, weekly_reports TO rsenglish_app;

INSERT INTO achievements(code,title,description,xp_reward) VALUES
('FIRST_ACTIVITY','Primeira atividade','Concluiu a primeira atividade do RS English.',20),
('STREAK_3','3 dias seguidos','Estudou inglês por 3 dias consecutivos.',30),
('STREAK_7','7 dias seguidos','Manteve uma semana de estudos.',70),
('VOCAB_25','25 palavras','Chegou a 25 palavras dominadas.',40),
('VOCAB_100','100 palavras','Chegou a 100 palavras dominadas.',100),
('REVIEW_10','Revisor consistente','Concluiu pelo menos 10 revisões.',50)
ON CONFLICT(code) DO NOTHING;
