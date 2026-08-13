# RS English v7 — Finalização da plataforma antes do WhatsApp

Esta etapa permite concluir o produto principal e testar o professor pelo navegador
enquanto o canal WhatsApp está indisponível.

## O que foi concluído

### Usuários
- login em PostgreSQL;
- perfis `admin`, `teacher`, `student`;
- senha com `password_hash`;
- fallback temporário para `ADMIN_USER`/`ADMIN_PASSWORD`;
- vínculo automático entre usuário aluno e `students`.

### Portal do aluno
- progresso;
- nível e meta;
- competências;
- XP;
- streak;
- revisões;
- atividades;
- vocabulário;
- preferências de estudo;
- Web Tester.

### Configuração do professor
- nome;
- personalidade;
- correção;
- idioma;
- máximo de correções por resposta.

### Produto
- organizações;
- base multi-tenant;
- planos;
- subscriptions;
- auditoria;
- settings.

### Canal Web
Novo workflow:
`rs-english-n8n-v7-web-tester.json`

Assim podemos validar:
Painel → PHP → n8n → Teacher → Evaluator → PostgreSQL
sem Evolution API.

---

# INSTALAÇÃO

## 1. Adminer

Execute na ordem:

1. `database/017_auth_multiuser.sql`
2. `database/018_preferences_settings.sql`
3. `database/019_plans_subscriptions.sql`
4. `database/020_audit_settings.sql`

## 2. PHP

Substitua:
- `src/auth.php`
- `public/login.php`
- `public/logout.php`

Adicione:

### Admin
- `public/admin/users.php`
- `public/admin/teacher-settings.php`

### Portal
- `public/portal/index.php`
- `public/portal/practice.php`
- `public/portal/activities.php`
- `public/portal/vocabulary.php`
- `public/portal/onboarding.php`

### Proxy Web
- `public/api/web/teacher.php`

## 3. Menu

Leia:
`HEADER-V7.txt`

Não substituí o header completo porque você já está com o style da v4/v5/v6.
Adicione apenas os links e condições de perfil.

## 4. n8n

Importe:
`rs-english-n8n-v7-web-tester.json`

Troque:
- `https://SEU_DOMINIO`
- `SUA_N8N_API_KEY`
- `SUA_OPENAI_API_KEY`
- `SEU_MODELO_OPENAI`

Ative o workflow.

A URL final será:
`https://SEU_N8N/webhook/rs-english-web`

## 5. EasyPanel

Adicione:
`N8N_WEB_TEACHER_URL=https://SEU_N8N/webhook/rs-english-web`

Faça redeploy do serviço PHP.

## 6. Primeiro usuário aluno

Acesse:
`/admin/users.php`

Crie:
- nome;
- username;
- telefone;
- senha;
- role = student.

O sistema:
1. procura `students` pelo telefone;
2. se não encontrar, cria;
3. cria `student_profiles`;
4. cria `app_users` ligado ao aluno.

Depois faça logout e entre com o usuário do aluno.

Ele será redirecionado para:
`/portal/index.php`

## 7. Testar sem WhatsApp

Entre como aluno e acesse:
`/portal/practice.php`

Fluxo:
Browser
→ `/api/web/teacher.php`
→ n8n `/webhook/rs-english-web`
→ context.php
→ Teacher
→ Evaluator
→ save-interaction.php
→ retorna para o navegador

O mesmo PostgreSQL é usado. Portanto:
- erros;
- vocabulário;
- XP;
- histórico;
- skills;
- progresso

continuam sendo registrados normalmente.

---

# O QUE FICA PARA DEPOIS DA VALIDAÇÃO

O núcleo de produto estará pronto.

Pontos opcionais/futuros:
- gateway real de pagamento;
- e-mail de recuperação de senha;
- convite por e-mail;
- pgvector para bases RAG grandes;
- app mobile/PWA;
- white-label;
- painel avançado de escola/professores;
- retomada do WhatsApp/Evolution.

O WhatsApp passa a ser somente mais um canal, não um bloqueio do projeto.
