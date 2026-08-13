-- RS English v5 - Biblioteca de Conteúdo + RAG simples no PostgreSQL

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

    source_id UUID NOT NULL
        REFERENCES knowledge_sources(id)
        ON DELETE CASCADE,

    chunk_index INTEGER NOT NULL,

    content TEXT NOT NULL,

    -- Nesta fase guardamos embedding como JSONB para não exigir pgvector.
    embedding JSONB,

    token_estimate INTEGER NOT NULL DEFAULT 0,

    level VARCHAR(5),
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

    level VARCHAR(5) NOT NULL,
    module_order INTEGER NOT NULL DEFAULT 0,

    description TEXT,

    objectives JSONB NOT NULL DEFAULT '[]'::jsonb,
    grammar_topics JSONB NOT NULL DEFAULT '[]'::jsonb,
    vocabulary_topics JSONB NOT NULL DEFAULT '[]'::jsonb,

    active BOOLEAN NOT NULL DEFAULT TRUE,

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_knowledge_sources_level
ON knowledge_sources(level, active, status);

CREATE INDEX IF NOT EXISTS idx_knowledge_chunks_source
ON knowledge_chunks(source_id, chunk_index);

CREATE INDEX IF NOT EXISTS idx_knowledge_chunks_filters
ON knowledge_chunks(level, category, active);

CREATE INDEX IF NOT EXISTS idx_curriculum_level_order
ON curriculum_modules(level, module_order);

GRANT SELECT, INSERT, UPDATE, DELETE
ON knowledge_sources, knowledge_chunks, curriculum_modules
TO rsenglish_app;

-- Currículo inicial enxuto. Poderemos aprofundar depois.
INSERT INTO curriculum_modules
(code,title,level,module_order,description,objectives,grammar_topics,vocabulary_topics)
VALUES
(
 'A1_INTRODUCTIONS','Introductions & Personal Information','A1',10,
 'Apresentações, dados pessoais e cumprimentos.',
 '["Introduce yourself","Ask and answer basic personal questions","Use basic greetings"]',
 '["verb_to_be","subject_pronouns","basic_questions"]',
 '["greetings","countries","jobs","personal_information"]'
),
(
 'A1_DAILY_ROUTINE','Daily Routine','A1',20,
 'Rotina diária e hábitos simples.',
 '["Describe a daily routine","Tell the time","Talk about habits"]',
 '["present_simple","adverbs_of_frequency"]',
 '["daily_routine","time","common_verbs"]'
),
(
 'A1_FOOD','Food & Drinks','A1',30,
 'Vocabulário básico de alimentação e pedidos.',
 '["Order simple food","Talk about likes and dislikes"]',
 '["countable_uncountable","some_any"]',
 '["food","drinks","restaurant"]'
),
(
 'A2_PAST_EVENTS','Past Events','A2',10,
 'Contar acontecimentos simples no passado.',
 '["Tell what happened yesterday","Describe a past experience","Ask simple past questions"]',
 '["past_simple","irregular_verbs","did_questions"]',
 '["past_time_expressions","travel","weekend"]'
),
(
 'A2_TRAVEL','Travel','A2',20,
 'Situações comuns de viagem.',
 '["Handle basic airport and hotel situations","Describe a trip","Ask for information"]',
 '["past_simple","future_plans","prepositions"]',
 '["airport","hotel","directions","transport"]'
),
(
 'A2_FUTURE','Future Plans','A2',30,
 'Planos e intenções.',
 '["Talk about plans","Make simple predictions","Arrange activities"]',
 '["going_to","will","present_continuous_future"]',
 '["plans","weekend","appointments"]'
),
(
 'B1_OPINIONS','Opinions & Discussions','B1',10,
 'Expressar e justificar opiniões.',
 '["Express an opinion","Agree and disagree politely","Give reasons and examples"]',
 '["linking_words","modals","comparatives"]',
 '["opinions","technology","work","society"]'
),
(
 'B1_STORYTELLING','Storytelling','B1',20,
 'Narrativas mais naturais sobre experiências.',
 '["Tell a structured story","Sequence events","Add context and detail"]',
 '["past_simple","past_continuous","present_perfect"]',
 '["experiences","emotions","events"]'
),
(
 'B1_WORK','Work & Professional English','B1',30,
 'Inglês para trabalho e conversas profissionais.',
 '["Discuss responsibilities","Participate in simple meetings","Explain a problem"]',
 '["present_perfect","modals","conditionals_basic"]',
 '["work","meetings","projects","problems"]'
)
ON CONFLICT (code) DO NOTHING;
