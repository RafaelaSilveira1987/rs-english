-- RS English v7 - Preparação para produto, planos e assinaturas

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

    organization_id UUID
        REFERENCES organizations(id)
        ON DELETE CASCADE,

    user_id UUID
        REFERENCES app_users(id)
        ON DELETE CASCADE,

    plan_id UUID NOT NULL
        REFERENCES plans(id)
        ON DELETE RESTRICT,

    status VARCHAR(30) NOT NULL DEFAULT 'trial',

    starts_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    ends_at TIMESTAMPTZ,

    external_provider VARCHAR(50),
    external_subscription_id VARCHAR(255),

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

INSERT INTO plans(code,name,price_monthly,features,limits)
VALUES
(
 'STARTER','Starter',0,
 '["WhatsApp/Web practice","Basic progress","Vocabulary review"]',
 '{"students":1,"monthly_messages":500}'
),
(
 'PRO','Pro',49.90,
 '["Full progress","Audio","RAG","Weekly reports","Activities"]',
 '{"students":1,"monthly_messages":5000}'
),
(
 'SCHOOL','School',299.90,
 '["Multiple students","Teachers","Reports","Knowledge base","Multi-tenant"]',
 '{"students":100,"monthly_messages":50000}'
)
ON CONFLICT(code) DO NOTHING;

GRANT SELECT, INSERT, UPDATE, DELETE
ON plans, subscriptions
TO rsenglish_app;
