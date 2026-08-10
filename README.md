# RS English — PHP + PostgreSQL + n8n

MVP para acompanhamento de alunos de inglês com:

- painel administrativo em PHP;
- PostgreSQL;
- acompanhamento de progresso;
- histórico de sessões;
- erros recorrentes;
- vocabulário;
- endpoints para integração com n8n/WhatsApp;
- arquitetura simples para EasyPanel.

## Arquitetura

WhatsApp -> Evolution API -> n8n -> RS English API (PHP) -> PostgreSQL

Painel Web -> PHP -> PostgreSQL

O n8n fica responsável por:
1. receber mensagem do WhatsApp;
2. transcrever áudio quando necessário;
3. buscar contexto pedagógico na API;
4. chamar Teacher IA;
5. chamar Evaluator IA;
6. salvar a interação pela API;
7. responder via Evolution API.

## EasyPanel

Crie um serviço App usando este repositório e o Dockerfile.

Porta interna:
80

Variáveis:
copie `.env.example` para as variáveis de ambiente do EasyPanel.

Não publique a porta 5432 do PostgreSQL.

## URLs principais

- `/login.php`
- `/index.php`
- `/students.php`
- `/student.php?id=UUID`
- `/api/health.php`

### n8n

Buscar contexto:
`GET /api/n8n/context.php?phone=5532...`

Header:
`X-API-Key: SUA_CHAVE`

Salvar interação:
`POST /api/n8n/save-interaction.php`

Header:
`X-API-Key: SUA_CHAVE`
`Content-Type: application/json`

Exemplo:
```json
{
  "phone": "5532980000000",
  "student_name": "Rafaela",
  "student_message": "Yesterday I go to supermarket",
  "teacher_message": "A more natural way is: Yesterday I went to the supermarket.",
  "message_type": "text",
  "mode": "conversation",
  "topic": "daily routine",
  "evaluation": {
    "grammar_score": 60,
    "vocabulary_score": 78,
    "fluency_score": 70,
    "comprehension_score": 85,
    "errors": [
      {
        "category": "grammar",
        "topic": "past_simple",
        "original": "I go",
        "corrected": "I went",
        "explanation": "Use past simple for completed actions.",
        "severity": "medium"
      }
    ],
    "skills": [
      {
        "code": "past_simple",
        "score": 60,
        "success": false
      }
    ]
  }
}
```

## Banco

O projeto pressupõe as tabelas criadas anteriormente:
- students
- student_profiles
- skills
- student_skills
- sessions
- messages
- student_errors
- vocabulary
- student_vocabulary
- activities
- student_activities
- assessments
- assessment_results
- knowledge_sources

Execute também:
`database/012_php_api_indexes.sql`
