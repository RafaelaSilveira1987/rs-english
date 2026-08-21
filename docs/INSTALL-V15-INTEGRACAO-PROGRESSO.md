# RS English v15 — Integração completa do progresso real

A v15 fecha o ciclo entre os eventos de aprendizagem e os dois painéis:

```text
WhatsApp / Portal / Áudio / Diagnóstico / Atividades
                    ↓
       eventos e evidências no PostgreSQL
                    ↓
   painel individual do aluno + visão geral do admin
```

## 1. Faça backup

Antes da atualização, faça backup do PostgreSQL e mantenha uma cópia da versão web atual.

Não apague tabelas, usuários, workflows ou credenciais.

## 2. Atualize os arquivos PHP

Substitua o projeto web pelos arquivos da v15 e faça o rebuild/redeploy do serviço `rs-english_web`.

## 3. Execute a migration

No banco `rs-english_postgres`, execute após a migration 032:

```text
database/033_learning_telemetry.sql
```

A migration cria:

- `student_skill_evidence`: evidências usadas no cálculo das oito competências;
- `student_learning_events`: eventos padronizados de aprendizagem;
- campos de duração, habilidade, dificuldade e acertos nas tentativas de atividade;
- consolidação de correções recorrentes;
- novos campos dos snapshots diários;
- registros-base para dados históricos já existentes.

A migration é idempotente, mas o backup continua obrigatório.

## 4. Endpoints integrados

A v15 atualiza a telemetria automaticamente nestes pontos:

```text
/api/n8n/save-interaction.php
/api/n8n/save-diagnostic.php
/api/n8n/save-diagnostic-feedback.php
/api/n8n/save-corrections.php
/api/n8n/start-conversation.php
/api/n8n/end-conversation.php
/api/n8n/complete-activity.php
/api/web/activity-submit.php
/api/web/voice.php
/api/voice/pronunciation-practice.php
```

Também foi adicionado:

```text
/api/n8n/refresh-progress.php
```

Esse endpoint recalcula as competências e grava o snapshot diário de um aluno ou de toda a base.

## 5. Snapshot diário no n8n

Importe:

```text
docs/fluxos/rs-english-n8n-v15-snapshot-diario.json
```

Configure no serviço n8n:

```env
RS_ENGLISH_API_URL=https://rsenglish.rsautomacaodigital.cloud
N8N_API_KEY=A_MESMA_CHAVE_INTERNA_DO_SERVICO_WEB
N8N_BLOCK_ENV_ACCESS_IN_NODE=false
```

O workflow é executado diariamente às 02:10 no fuso `America/Sao_Paulo`.

Ele não substitui os workflows v13. Ele apenas consolida e registra os snapshots diários.

## 6. O que passa a alimentar o progresso

### Conversas

- canal: WhatsApp ou web;
- mensagem de texto ou áudio;
- duração mensurável;
- competências avaliadas;
- correções detectadas;
- XP e data do evento.

### Diagnóstico

- nível estimado;
- gramática;
- vocabulário;
- fala/interação;
- compreensão oral;
- leitura;
- escrita;
- fluência;
- pronúncia, quando houver evidência.

A autoavaliação inicial continua sendo somente o ponto de partida. O nível final depende das evidências registradas.

### Atividades

- resposta;
- nota;
- acertos e total de questões;
- habilidade trabalhada;
- dificuldade;
- duração;
- tentativa;
- feedback e correções.

### Correções

Correções iguais são consolidadas por uma chave canônica. O sistema registra recorrência, última ocorrência, canal e situação de revisão.

## 7. Painel do aluno

As telas abaixo usam a mesma camada de progresso do admin:

```text
/portal/index.php
/portal/progress.php
```

O aluno visualiza:

- recomendação atual da Emma;
- competências e quantidade de evidências;
- tempo de estudo real;
- dias ativos;
- meta semanal;
- atividades e nota média;
- vocabulário dominado;
- erros recorrentes e correções resolvidas;
- conversas e áudios;
- histórico por snapshots.

Uma competência com nota zero, mas já avaliada, aparece como `0%`. Uma competência sem qualquer evidência aparece como `Ainda não medida`.

## 8. Painel administrativo

Acesse:

```text
/admin/progress.php
```

O admin visualiza:

- alunos ativos em 7 e 30 dias;
- tempo total de estudo;
- média das competências;
- meta semanal média;
- diagnósticos concluídos;
- conclusão de atividades;
- erros recorrentes;
- distribuição QECR;
- alunos que precisam de atenção;
- motivo do alerta e próxima intervenção recomendada;
- dados individuais iguais aos apresentados ao aluno.

A ficha completa continua em:

```text
/student.php?id=UUID_DO_ALUNO
```

## 9. Teste recomendado

Escolha um aluno de teste e siga esta sequência:

1. Abra a ficha no admin e anote os indicadores atuais.
2. Envie uma mensagem para a Emma.
3. Conclua uma atividade pelo painel.
4. Faça uma prática de áudio ou pronúncia.
5. Abra novamente `/portal/progress.php` e `/student.php?id=...`.
6. Confirme que os valores centrais são os mesmos nos dois acessos.
7. Execute manualmente o workflow de snapshot e confirme a atualização do histórico.

## 10. Consultas de verificação

### Últimos eventos

```sql
SELECT event_type, channel, duration_seconds, score, occurred_at
FROM student_learning_events
WHERE student_id = 'UUID_DO_ALUNO'
ORDER BY occurred_at DESC
LIMIT 30;
```

### Evidências das competências

```sql
SELECT skill_code, source, score, weight, observed_at
FROM student_skill_evidence
WHERE student_id = 'UUID_DO_ALUNO'
ORDER BY observed_at DESC;
```

### Snapshot diário

```sql
SELECT snapshot_date, overall_level, skill_average,
       study_minutes_total, active_days_30d, recurring_errors
FROM student_progress_snapshots
WHERE student_id = 'UUID_DO_ALUNO'
ORDER BY snapshot_date DESC;
```

## 11. Observações

- A v15 não exige trocar os modelos econômicos da v13.
- Não ative duas versões com o mesmo webhook.
- O tempo de conversas de texto não é inventado. Apenas eventos com duração informada entram como minutos.
- Os arquivos foram validados estaticamente. A execução da migration e das consultas precisa ser confirmada no PostgreSQL real da instalação.
