# RS English v14 — Progresso real do aluno e visão geral do admin

## Objetivo

A v14 substitui indicadores isolados ou dependentes de preenchimento manual por uma camada central de progresso calculada a partir dos registros reais já existentes no RS English.

Fontes usadas:

- `student_profiles`: nível, competências e XP;
- `sessions`: sessões, frequência e desempenho de conversação;
- `messages`: interações do aluno;
- `student_activities` + `activities`: conclusão, nota e tempo estimado;
- `student_vocabulary`: palavras registradas, dominadas e revisões;
- `student_errors`: correções em aberto e evolução;
- `voice_conversations`: minutos reais de áudio;
- `weekly_goals`: metas semanais;
- `diagnostic_reports`: diagnóstico e classificação QECR;
- `student_achievements`: conquistas;
- `weekly_reports`: relatórios pedagógicos.

## 1. Banco de dados

Execute após a migration 031:

```sql
database/032_real_progress.sql
```

A migration cria `student_progress_snapshots`, usada para registrar a evolução diária dos indicadores.

Os dados atuais aparecem imediatamente porque são calculados das tabelas históricas já existentes. A comparação histórica de `skill_average` por snapshot começa a ser construída a partir da instalação da v14.

## 2. Arquivos principais novos/alterados

```text
src/progress.php
public/portal/index.php
public/portal/progress.php
public/index.php
public/students.php
public/student.php
public/admin/progress.php
templates/header.php
public/assets/css/app.css
```

Também foram conectados ao refresh de progresso:

```text
public/api/n8n/save-interaction.php
public/api/n8n/save-diagnostic.php
public/api/n8n/save-corrections.php
public/api/n8n/complete-activity.php
public/api/web/activity-submit.php
public/api/web/voice.php
```

## 3. O que o aluno passa a ver

- nível atual;
- média somente das competências já medidas;
- competências ainda não avaliadas como “Ainda não medida”, não como 0%;
- sequência calculada pelos dias reais de atividade;
- atividade dos últimos 14 dias;
- meta semanal calculada com registros reais;
- atividades concluídas / atribuídas e nota média;
- vocabulário dominado / total;
- sessões e mensagens reais;
- correções em aberto;
- minutos de áudio;
- conquistas e relatórios.

## 4. O que o admin passa a ver

Menu:

```text
Análise > Progresso geral
```

Indicadores:

- alunos ativos nos últimos 7 dias;
- alunos que precisam de atenção;
- percentual de diagnósticos concluídos;
- média das competências já medidas;
- média de execução da meta semanal;
- taxa de conclusão de atividades;
- sessões dos últimos 7 dias;
- palavras dominadas na base;
- distribuição por nível QECR;
- movimento dos últimos 14 dias;
- tabela comparativa de todos os alunos.

## 5. Regra de frequência

A situação do aluno é calculada pela última atividade real:

```text
Ativo        = até 3 dias
Atenção      = 4 a 7 dias
Inativo      = mais de 7 dias
Não iniciou  = sem atividade registrada
```

## 6. Minutos de estudo

A plataforma não inventa duração para conversas de texto.

`completed_minutes` usa dados que podem ser medidos:

- `activities.estimated_minutes` das atividades concluídas;
- duração real dos áudios em `voice_conversations`;
- valor já salvo em `weekly_goals`, quando for maior.

Assim, mensagens de texto contam como atividade/frequência, mas não são convertidas artificialmente em minutos.

## 7. Verificação após deploy

Confirme a migration:

```sql
SELECT COUNT(*) FROM student_progress_snapshots;
```

Depois acesse um aluno e confira:

```sql
SELECT *
FROM student_progress_snapshots
ORDER BY snapshot_date DESC, updated_at DESC
LIMIT 20;
```

Abra no navegador:

```text
/portal/index.php
/portal/progress.php
/index.php
/students.php
/admin/progress.php
```

## 8. Ordem de implantação

1. Backup do PostgreSQL.
2. Substituir os arquivos pela v14.
3. Executar `database/032_real_progress.sql`.
4. Fazer rebuild/redeploy do serviço web.
5. Entrar primeiro com um aluno e validar os números.
6. Entrar como admin e comparar o mesmo aluno no painel geral.

Não é necessário importar um novo workflow n8n nesta versão. A v14 reaproveita os workflows da v13 e apenas passa a atualizar a camada de progresso após os eventos já existentes.
