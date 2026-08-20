# RS English v12 — Emma adaptativa e acesso automático do aluno

## O que mudou

Esta versão implementa dois pontos em conjunto:

1. A Emma sempre inicia o primeiro contato em português e adapta a dificuldade antes de decidir quanto inglês utilizar.
2. O cadastro do aluno criado pelo WhatsApp passa a gerar um usuário do portal vinculado ao mesmo `student_id`, sem duplicar progresso, mensagens, diagnóstico ou atividades.

## 1. Banco de dados

Execute, depois da migration 030:

```sql
\i database/031_adaptive_onboarding_access.sql
```

No Adminer, abra o arquivo e execute o conteúdo completo.

A migration acrescenta:

- `support_mode`;
- `teaching_mode`;
- idioma preferido das explicações;
- confiança do diagnóstico;
- dados de ativação do usuário;
- tabela de links de ativação com uso único e validade.

## 2. Arquivos PHP

Suba o projeto completo. Os arquivos principais alterados são:

- `src/access.php`;
- `src/auth.php`;
- `src/portal.php`;
- `public/api/n8n/context.php`;
- `public/api/n8n/save-diagnostic.php`;
- `public/activate-account.php`;
- `public/first-access.php`;
- `public/login.php`;
- `public/portal/profile.php`;
- `public/portal/onboarding.php`;
- `public/admin/accesses.php`;
- `templates/header.php`.

Confirme no EasyPanel:

```env
APP_URL=https://rsenglish.rsautomacaodigital.cloud
```

## 3. Workflow n8n

Importe os dois workflows:

```text
docs/fluxos/rs-english-n8n-v12-adaptive-access.json
docs/fluxos/rs-english-n8n-v12-web-portal-adaptive.json
```

Depois selecione a credencial OpenAI nos nós:

- Diagnóstico IA1;
- Teacher IA1;
- Evaluator IA1;
- Transcrever Áudio.

Mantenha as variáveis:

```env
N8N_API_KEY=...
EVOLUTION_API_KEY=...
N8N_BLOCK_ENV_ACCESS_IN_NODE=false
```

Ative apenas uma versão do workflow que use o webhook `/rs-english`. Desative o workflow antigo antes de ativar a v12 para evitar respostas duplicadas.

O workflow web substitui a versão v11 do portal. Mantenha `N8N_WEB_TEACHER_URL` apontando para o webhook configurado nele.

## 4. Como funciona o primeiro contato

A primeira mensagem da Emma é sempre em português e apresenta cinco caminhos:

1. começar do zero;
2. reconhecer palavras com prática guiada;
3. usar frases simples com apoio;
4. conversar principalmente em inglês;
5. deixar a Emma descobrir o ponto de partida durante a conversa.

A escolha define apenas o ponto inicial. O nível QECR final depende das evidências coletadas nas etapas seguintes.

## 5. Modos salvos no perfil

### Apoio de idioma

- `pt_first` — português primeiro e inglês em pequenas doses;
- `bilingual` — português e inglês;
- `english_first` — inglês primeiro e português quando necessário;
- `english_only` — imersão em inglês.

### Forma de ensino

- `foundations`;
- `guided`;
- `guided_conversation`;
- `conversation`;
- `immersion`.

O aluno pode ajustar essas preferências em:

```text
Portal do aluno → Meu perfil
```

## 6. Criação automática do usuário

Quando `context.php` localiza ou cria o aluno pelo telefone, ele também:

1. procura um `app_users` já vinculado ao mesmo `student_id`;
2. aproveita o usuário existente pelo telefone, quando seguro;
3. cria um usuário com status `pending_activation` quando ainda não existe;
4. gera um link de ativação de uso único;
5. devolve o link ao n8n;
6. envia o link ao aluno pelo mesmo WhatsApp.

O aluno cria a senha em:

```text
/activate-account.php?token=...
```

Depois entra usando o número do WhatsApp.

Para receber outro link, o aluno envia:

```text
ACESSO
```

## 7. Alunos já existentes

No painel administrativo, acesse:

```text
Administração → Acessos dos alunos
```

Use:

- **Criar acessos pendentes** para vincular todos os alunos ainda sem usuário;
- **Gerar novo link** para emitir uma ativação individual.

O link deve ser enviado somente ao próprio aluno.

## 8. Teste recomendado

Use um telefone ainda não cadastrado:

1. envie qualquer mensagem para a Emma;
2. confirme a recepção em português;
3. confirme o recebimento separado do link do portal;
4. escolha a opção 1;
5. verifique se a próxima tarefa ensina do zero em português;
6. abra o link e crie a senha;
7. entre no portal com o telefone;
8. confirme que diagnóstico, histórico e perfil pertencem ao mesmo aluno.

Depois repita com as opções 3, 4 e 5.

## 9. Segurança

- os tokens são armazenados somente como hash;
- cada link é de uso único;
- o link expira em 7 dias;
- ao gerar novo link, os anteriores são invalidados;
- o portal não cria um segundo cadastro de aluno;
- não exponha `N8N_API_KEY`, `EVOLUTION_API_KEY` ou `N8N_ENCRYPTION_KEY`.
