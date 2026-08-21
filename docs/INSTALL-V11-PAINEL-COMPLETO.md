# Instalação — RS English v11.0

## 1. Faça backup

Antes do deploy, faça backup do banco PostgreSQL e mantenha uma cópia da versão web atual.

Exemplo no serviço PostgreSQL:

```bash
pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" > /tmp/rs_english_pre_v11.sql
```

## 2. Atualize os arquivos

Substitua o conteúdo do serviço web pelo conteúdo deste pacote. Não substitua o `.env` de produção por `.env.example`.

## 3. Aplique a migration

Para uma base já atualizada até a v10.7:

```bash
psql -U SEU_USUARIO -d SEU_BANCO -f database/030_student_portal_complete.sql
```

A migration adiciona:

- preferências de interface, idioma e lembrete;
- dados de tentativa e feedback nas atividades;
- campos detalhados do diagnóstico;
- tópico e gravidade das correções;
- tabela `activity_attempts`;
- tabela `study_events`;
- índices e permissões.

## 4. Configure as variáveis

Obrigatórias para conversa web:

```env
N8N_API_KEY=CHAVE_INTERNA
N8N_WEB_TEACHER_URL=https://n8n.rsautomacaodigital.cloud/webhook/rs-english-web
```

Opcional para avaliação de atividades por IA:

```env
N8N_WEB_ACTIVITY_URL=https://n8n.rsautomacaodigital.cloud/webhook/rs-english-activity
```

Obrigatórias para áudio:

```env
OPENAI_API_KEY=CHAVE_OPENAI
OPENAI_TEXT_MODEL=gpt-5.6-luna
OPENAI_TRANSCRIPTION_MODEL=gpt-4o-mini-transcribe
OPENAI_TTS_MODEL=gpt-4o-mini-tts
```

Não coloque chaves diretamente em arquivos JSON, JavaScript ou páginas PHP.

## 5. Volume de áudio

Mantenha persistente:

```text
/var/www/html/storage/voice
```

O usuário do Apache precisa de leitura e escrita nesse diretório.

## 6. Rebuild e deploy

No EasyPanel, faça um rebuild do serviço `rs-english_web` e depois implante uma única vez.

O container expõe a porta interna:

```text
80
```

## 7. Teste de banco

Abra:

```text
https://rsenglish.rsautomacaodigital.cloud/api/health.php
```

Depois confira os logs do serviço web para qualquer erro SQL.

## 8. Testes do aluno

Entre com um usuário que tenha:

- `app_users.role = 'student'`;
- `app_users.student_id` preenchido;
- registro correspondente em `students`;
- registro em `student_profiles`.

Teste nesta ordem:

1. `/portal/index.php`;
2. `/portal/diagnostic.php`;
3. `/portal/practice.php`;
4. `/portal/corrections.php`;
5. `/portal/vocabulary.php`;
6. `/portal/activities.php`;
7. `/portal/progress.php`;
8. `/portal/history.php`;
9. `/portal/profile.php`.


## 9. Importe os fluxos web v11

No n8n, importe:

```text
docs/fluxos/rs-english-n8n-v11-web-portal.json
docs/fluxos/rs-english-n8n-v11-activity-evaluator.json
```

Nos nós da OpenAI, selecione a credencial já cadastrada. Depois ative os dois workflows e confirme as URLs de produção.

O fluxo principal usa:

```text
/webhook/rs-english-web
```

O avaliador de atividades usa:

```text
/webhook/rs-english-activity
```

## 10. Contrato do webhook web

O portal envia para `N8N_WEB_TEACHER_URL`:

```json
{
  "student_id": "uuid",
  "name": "Aluno",
  "phone": "5532...",
  "message": "Hello",
  "message_type": "text",
  "channel": "web",
  "mode": "conversation",
  "topic": "daily_life",
  "conversation": {
    "style": "guided",
    "max_turns": 10
  },
  "correction_mode": "balanced"
}
```

No diagnóstico, `mode` será `diagnostic` e o tópico padrão será `initial_diagnostic`.

Resposta esperada:

```json
{
  "teacher_message": "Resposta da Emma",
  "evaluation": {
    "errors": []
  },
  "diagnostic": {
    "complete": false
  }
}
```

## 11. Avaliação de atividades por IA

Quando `N8N_WEB_ACTIVITY_URL` estiver configurada, o webhook deve retornar:

```json
{
  "score": 85,
  "feedback": "Boa resposta. Ajuste a ordem das palavras."
}
```

Sem esse webhook, a atividade continua funcional com avaliação local e registro de XP.

## 12. Segurança

- mantenha `APP_DEBUG=false` em produção;
- use HTTPS;
- não exponha `N8N_API_KEY` no navegador;
- mantenha o endpoint n8n protegido pelo mesmo cabeçalho `X-API-Key`;
- rotacione chaves que tenham aparecido em logs ou capturas de tela;
- não altere a `N8N_ENCRYPTION_KEY` usada pelas credenciais atuais.

## 13. Observação sobre o QECR

O painel apresenta uma estimativa pedagógica alinhada ao QECR. O texto da tela deixa claro que o resultado não substitui uma certificação oficial.
