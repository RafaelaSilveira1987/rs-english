-- RS English v8 - Consolidação final
-- Seguro para rodar após 012..020.
-- O objetivo é criar/ajustar o que eventualmente tenha ficado para trás.

CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- organizations
CREATE TABLE IF NOT EXISTS organizations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(180) NOT NULL,
    slug VARCHAR(120) UNIQUE NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- students extras / profiles extras
ALTER TABLE student_profiles
    ADD COLUMN IF NOT EXISTS diagnostic_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS diagnostic_step INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS diagnostic_started_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS diagnostic_completed_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS estimated_level VARCHAR(10);

-- study plans
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

-- memory fields
ALTER TABLE student_errors
    ADD COLUMN IF NOT EXISTS canonical_key VARCHAR(255),
    ADD COLUMN IF NOT EXISTS mastery_score NUMERIC(5,2) NOT NULL DEFAULT 0;

ALTER TABLE vocabulary
    ADD COLUMN IF NOT EXISTS normalized_word VARCHAR(150);

ALTER TABLE student_vocabulary
    ADD COLUMN IF NOT EXISTS interval_days INTEGER NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS ease_factor NUMERIC(4,2) NOT NULL DEFAULT 2.50;

UPDATE vocabulary
SET normalized_word = lower(trim(word))
WHERE normalized_word IS NULL OR normalized_word = '';

UPDATE student_errors
SET canonical_key = lower(
    regexp_replace(
        COALESCE(topic, category, 'other'),
        '[^a-zA-Z0-9_]+',
        '_',
        'g'
    )
)
WHERE canonical_key IS NULL OR canonical_key = '';

-- knowledge
ALTER TABLE knowledge_sources
    ADD COLUMN IF NOT EXISTS status VARCHAR(30) NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS original_filename VARCHAR(255),
    ADD COLUMN IF NOT EXISTS mime_type VARCHAR(120),
    ADD COLUMN IF NOT EXISTS file_size BIGINT,
    ADD COLUMN IF NOT EXISTS content_text TEXT,
    ADD COLUMN IF NOT EXISTS chunk_count INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS indexed_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

CREATE TABLE IF NOT EXISTS knowledge_chunks (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    source_id UUID NOT NULL REFERENCES knowledge_sources(id) ON DELETE CASCADE,
    chunk_index INTEGER NOT NULL,
    content TEXT NOT NULL,
    embedding JSONB,
    token_estimate INTEGER NOT NULL DEFAULT 0,
    level VARCHAR(10),
    category VARCHAR(100),
    tags TEXT[],
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(source_id, chunk_index)
);

CREATE TABLE IF NOT EXISTS curriculum_modules (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    code VARCHAR(100) UNIQUE NOT NULL,
    title VARCHAR(200) NOT NULL,
    level VARCHAR(10) NOT NULL,
    module_order INTEGER NOT NULL DEFAULT 0,
    description TEXT,
    objectives JSONB NOT NULL DEFAULT '[]'::jsonb,
    grammar_topics JSONB NOT NULL DEFAULT '[]'::jsonb,
    vocabulary_topics JSONB NOT NULL DEFAULT '[]'::jsonb,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- weekly goals / achievements / reports
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

ALTER TABLE activities
    ADD COLUMN IF NOT EXISTS xp_reward INTEGER NOT NULL DEFAULT 10,
    ADD COLUMN IF NOT EXISTS estimated_minutes INTEGER NOT NULL DEFAULT 10,
    ADD COLUMN IF NOT EXISTS generated_by VARCHAR(30) NOT NULL DEFAULT 'system';

ALTER TABLE student_activities
    ADD COLUMN IF NOT EXISTS xp_earned INTEGER NOT NULL DEFAULT 0;

-- users
CREATE TABLE IF NOT EXISTS app_users (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id UUID REFERENCES organizations(id) ON DELETE SET NULL,
    student_id UUID REFERENCES students(id) ON DELETE SET NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(255) UNIQUE,
    phone VARCHAR(30) UNIQUE,
    username VARCHAR(120) UNIQUE,
    password_hash TEXT NOT NULL,
    role VARCHAR(30) NOT NULL DEFAULT 'student',
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    last_login_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS user_preferences (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NOT NULL UNIQUE REFERENCES app_users(id) ON DELETE CASCADE,
    theme VARCHAR(30) NOT NULL DEFAULT 'system',
    language VARCHAR(10) NOT NULL DEFAULT 'pt-BR',
    notifications JSONB NOT NULL DEFAULT '{}'::jsonb,
    preferences JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS teacher_settings (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id UUID REFERENCES organizations(id) ON DELETE CASCADE,
    teacher_name VARCHAR(120) NOT NULL DEFAULT 'Emma',
    teacher_personality VARCHAR(50) NOT NULL DEFAULT 'balanced',
    default_correction_mode VARCHAR(30) NOT NULL DEFAULT 'balanced',
    default_language_mix VARCHAR(30) NOT NULL DEFAULT 'adaptive',
    max_corrections_per_message INTEGER NOT NULL DEFAULT 2,
    teacher_rules JSONB NOT NULL DEFAULT '{}'::jsonb,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS student_preferences (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    student_id UUID NOT NULL UNIQUE REFERENCES students(id) ON DELETE CASCADE,
    daily_minutes INTEGER NOT NULL DEFAULT 20,
    weekly_days INTEGER NOT NULL DEFAULT 5,
    preferred_topics JSONB NOT NULL DEFAULT '[]'::jsonb,
    avoided_topics JSONB NOT NULL DEFAULT '[]'::jsonb,
    focus_mode VARCHAR(30) NOT NULL DEFAULT 'conversation',
    correction_mode VARCHAR(30) NOT NULL DEFAULT 'balanced',
    preferred_study_time VARCHAR(30),
    notes TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- plans
CREATE TABLE IF NOT EXISTS plans (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    code VARCHAR(80) UNIQUE NOT NULL,
    name VARCHAR(120) NOT NULL,
    price_monthly NUMERIC(10,2) NOT NULL DEFAULT 0,
    currency VARCHAR(10) NOT NULL DEFAULT 'BRL',
    limits JSONB NOT NULL DEFAULT '{}'::jsonb,
    features JSONB NOT NULL DEFAULT '[]'::jsonb,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS subscriptions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    organization_id UUID REFERENCES organizations(id) ON DELETE CASCADE,
    user_id UUID REFERENCES app_users(id) ON DELETE CASCADE,
    plan_id UUID NOT NULL REFERENCES plans(id) ON DELETE RESTRICT,
    status VARCHAR(30) NOT NULL DEFAULT 'trial',
    starts_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    ends_at TIMESTAMPTZ,
    external_provider VARCHAR(50),
    external_subscription_id VARCHAR(255),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- audit/settings
CREATE TABLE IF NOT EXISTS audit_logs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID REFERENCES app_users(id) ON DELETE SET NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100),
    entity_id VARCHAR(255),
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
    ip_address VARCHAR(80),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS app_settings (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    setting_key VARCHAR(150) UNIQUE NOT NULL,
    setting_value JSONB NOT NULL DEFAULT '{}'::jsonb,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- indexes
CREATE UNIQUE INDEX IF NOT EXISTS uq_vocabulary_normalized_word
ON vocabulary(normalized_word)
WHERE normalized_word IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_student_error_active_topic
ON student_errors(student_id, canonical_key)
WHERE status='learning' AND canonical_key IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_study_plans_student_status
ON study_plans(student_id,status);

CREATE INDEX IF NOT EXISTS idx_knowledge_chunks_filters
ON knowledge_chunks(level,category,active);

CREATE INDEX IF NOT EXISTS idx_curriculum_level_order
ON curriculum_modules(level,module_order);

CREATE INDEX IF NOT EXISTS idx_weekly_goals_student_week
ON weekly_goals(student_id,week_start DESC);

CREATE INDEX IF NOT EXISTS idx_weekly_reports_student_week
ON weekly_reports(student_id,week_start DESC);

CREATE INDEX IF NOT EXISTS idx_app_users_role_status
ON app_users(role,status);

CREATE INDEX IF NOT EXISTS idx_audit_logs_created
ON audit_logs(created_at DESC);

-- grants
GRANT SELECT, INSERT, UPDATE, DELETE
ON ALL TABLES IN SCHEMA public
TO rsenglish_app;

GRANT USAGE, SELECT, UPDATE
ON ALL SEQUENCES IN SCHEMA public
TO rsenglish_app;

-- seed teacher settings
INSERT INTO teacher_settings(
    teacher_name,teacher_personality,default_correction_mode,
    default_language_mix,max_corrections_per_message
)
SELECT 'Emma','balanced','balanced','adaptive',2
WHERE NOT EXISTS (SELECT 1 FROM teacher_settings);

-- seed plans
INSERT INTO plans(code,name,price_monthly,features,limits)
VALUES
('STARTER','Starter',0,'["Web practice","Basic progress","Vocabulary review"]','{"students":1,"monthly_messages":500}'),
('PRO','Pro',49.90,'["Full progress","Audio","RAG","Weekly reports","Activities"]','{"students":1,"monthly_messages":5000}'),
('SCHOOL','School',299.90,'["Multiple students","Teachers","Reports","Knowledge base","Multi-tenant"]','{"students":100,"monthly_messages":50000}')
ON CONFLICT(code) DO NOTHING;

-- seed achievements
INSERT INTO achievements(code,title,description,xp_reward)
VALUES
('FIRST_ACTIVITY','Primeira atividade','Concluiu a primeira atividade do RS English.',20),
('STREAK_3','3 dias seguidos','Estudou inglês por 3 dias consecutivos.',30),
('STREAK_7','7 dias seguidos','Manteve uma semana de estudos.',70),
('VOCAB_25','25 palavras','Chegou a 25 palavras dominadas.',40),
('VOCAB_100','100 palavras','Chegou a 100 palavras dominadas.',100),
('REVIEW_10','Revisor consistente','Concluiu pelo menos 10 revisões.',50)
ON CONFLICT(code) DO NOTHING;
