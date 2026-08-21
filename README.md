# RS English v13 — Emma adaptativa, portal completo e modelos econômicos

Versão consolidada em PHP 8.3 + PostgreSQL para o portal do aluno, integrada ao n8n, à Emma e aos dados já registrados pelo WhatsApp.

## O que foi implementado

### Portal do aluno
- login e sessão vinculados ao `student_id`;
- dashboard com nível, diagnóstico, XP, sequência, atividades, correções e vocabulário;
- diagnóstico detalhado com estimativa QECR, competências, evidências, pontos fortes, prioridades, recomendações e plano inicial;
- conversação com a Emma por texto e áudio;
- histórico de mensagens e correções exibidas durante a conversa;
- tela de correções com filtros por categoria e gravidade;
- vocabulário com status e revisão;
- atividades pendentes e concluídas;
- execução de atividade com resposta, pontuação, feedback, XP e histórico de tentativas;
- progresso semanal, competências, metas, conquistas e relatórios;
- linha do tempo unificada de mensagens, atividades, correções, áudio e eventos de estudo;
- perfil com dados pessoais, senha, modo de correção, idioma de apoio, voz, autoplay, tema, formato, duração de conversa e lembretes;
- layout claro e responsivo para desktop, tablet e celular.

### Backend e banco
- helpers centralizados em `src/portal.php`;
- endpoint `public/api/web/activity-submit.php`;
- suporte a `mode=conversation|diagnostic` nos endpoints web de texto e áudio;
- persistência ampliada do relatório de diagnóstico;
- migration `database/030_student_portal_complete.sql`;
- tabelas `activity_attempts` e `study_events`;
- novos campos de preferências, atividades, diagnóstico e correções.

## Instalação

Leia primeiro:

```text
docs/INSTALL-V11-PAINEL-COMPLETO.md
```

Para uma instalação já atualizada até a v10.7, execute somente:

```text
database/030_student_portal_complete.sql
```

Depois faça o rebuild/redeploy do serviço web.

## Variáveis principais

```env
APP_URL=https://rsenglish.rsautomacaodigital.cloud
N8N_API_KEY=chave-interna
N8N_WEB_TEACHER_URL=https://n8n.rsautomacaodigital.cloud/webhook/rs-english-web
N8N_WEB_ACTIVITY_URL=https://n8n.rsautomacaodigital.cloud/webhook/rs-english-activity
OPENAI_API_KEY=chave-openai
OPENAI_TEXT_MODEL=gpt-5.6-luna
OPENAI_TRANSCRIPTION_MODEL=gpt-4o-mini-transcribe
OPENAI_TTS_MODEL=gpt-4o-mini-tts
```

`N8N_WEB_ACTIVITY_URL` é opcional. Sem ela, o portal usa uma avaliação local determinística para atividades com resposta esperada e registra respostas abertas como concluídas. Para feedback pedagógico da IA, configure o webhook específico.

## Fluxos n8n incluídos

```text
docs/fluxos/rs-english-n8n-v11-web-portal.json
docs/fluxos/rs-english-n8n-v11-activity-evaluator.json
docs/fluxos/rs-english-n8n-v10-8-qecr-corrigido.json
```

Importe o fluxo v11 do portal, selecione a credencial OpenAI nos nós indicados e ative-o. O endpoint web envia o campo `mode`, que o fluxo preserva e trata:

```json
{
  "mode": "conversation"
}
```

ou:

```json
{
  "mode": "diagnostic"
}
```

A resposta esperada pelo painel é:

```json
{
  "teacher_message": "...",
  "evaluation": {},
  "diagnostic": {}
}
```

## Validações realizadas no pacote

- lint de todos os arquivos PHP;
- validação sintática dos arquivos JavaScript;
- validação JSON dos fluxos n8n;
- conferência da estrutura do ZIP.

A validação definitiva de consultas e permissões deve ser feita após aplicar a migration no PostgreSQL real da instalação.

## Versão 12 — acolhimento adaptativo e acesso automático

Execute `database/031_adaptive_onboarding_access.sql` e importe `docs/fluxos/rs-english-n8n-v12-adaptive-access.json`.

Guia: `docs/INSTALL-V12-ADAPTIVE-ACCESS.md`.


## Versão 13 — modelos econômicos em todo o projeto

Todos os fluxos incluídos foram padronizados para usar variáveis de ambiente, sem modelo de texto caro fixado no JSON:

```env
OPENAI_TEXT_MODEL=gpt-5.6-luna
OPENAI_TRANSCRIPTION_MODEL=gpt-4o-mini-transcribe
OPENAI_TTS_MODEL=gpt-4o-mini-tts
```

No serviço **n8n**, configure pelo menos `OPENAI_TEXT_MODEL` e `OPENAI_TRANSCRIPTION_MODEL`. No serviço **web/PHP**, configure `OPENAI_TRANSCRIPTION_MODEL` e `OPENAI_TTS_MODEL`.

Fluxos principais da v13:

```text
docs/fluxos/rs-english-n8n-v13-adaptive-economico.json
docs/fluxos/rs-english-n8n-v13-web-portal-economico.json
docs/fluxos/rs-english-n8n-v13-activity-evaluator-economico.json
```

Guia: `docs/INSTALL-V13-MODELOS-ECONOMICOS.md`.

## Versão 14 — progresso real do aluno e do admin

A v14 centraliza os indicadores em `src/progress.php` e calcula o avanço usando os registros reais de sessões, mensagens, atividades, vocabulário, correções, áudio, diagnóstico e metas.

Execute:

```text
database/032_real_progress.sql
```

Nova visão administrativa:

```text
/admin/progress.php
```

No portal do aluno, `/portal/index.php` e `/portal/progress.php` agora usam a mesma fonte de cálculo que o admin. Assim, o número exibido para o aluno e o número visto pela gestão vêm dos mesmos registros.

Guia: `docs/INSTALL-V14-PROGRESSO-REAL.md`.
