# RS English v16 — Gestão de acessos e senhas

## Objetivo

Permitir que o acesso deixe de depender somente do telefone e possa ser administrado tanto pelo aluno quanto pelo administrador.

## Recursos implementados

### Portal do aluno

- edição do nome de usuário em `Meu perfil`;
- manutenção do login por usuário, e-mail ou telefone;
- alteração de senha diretamente em `Meu perfil`;
- alteração de senha também pela rota `/change-password.php`;
- escolha do nome de usuário durante a ativação do primeiro acesso;
- validação de usuário único sem diferenciar maiúsculas de minúsculas;
- usuário manual não pode ser somente numérico;
- encerramento das sessões anteriores após troca ou redefinição de senha.

### Portal administrativo

- botão `Editar acesso` na lista de usuários;
- botão `Editar acesso` em `Acessos dos alunos`;
- atalho para edição na ficha pedagógica do aluno;
- edição de nome, usuário, e-mail, telefone e status;
- redefinição de senha sem conhecer a senha atual;
- opção para obrigar o aluno a trocar a senha no próximo login;
- sincronização de nome, e-mail e telefone entre `app_users` e `students`;
- suporte para editar contas de aluno, professor e administrador.

### Segurança

A migration adiciona `auth_version`. Quando a senha é alterada ou a conta é desativada, sessões anteriores deixam de ser aceitas.

## Instalação

### 1. Backup

Faça backup do PostgreSQL e do código atual.

### 2. Migration

Execute no Adminer:

```text
database/034_user_access_management.sql
```

A migration deve ser executada antes ou imediatamente junto ao deploy da v16.

### 3. Código

Atualize os arquivos do projeto e faça o redeploy do serviço web no EasyPanel.

Esta versão já contém o `Dockerfile` válido, iniciado por:

```dockerfile
FROM php:8.3-apache
```

O `.dockerignore` permanece em arquivo separado.

### 4. Teste como administrador

Acesse:

```text
/admin/users.php
```

Abra um usuário em `Editar acesso` e teste:

1. alterar o usuário de telefone para um nome, como `rafaela.silveira`;
2. definir uma senha temporária;
3. marcar `Exigir troca no próximo login`;
4. entrar como aluno usando o novo usuário;
5. confirmar o redirecionamento para troca de senha.

### 5. Teste como aluno

Acesse:

```text
/portal/profile.php
```

Confirme que o aluno consegue:

- alterar o próprio nome de usuário;
- alterar a senha informando a senha atual;
- continuar entrando com telefone, e-mail ou novo usuário.

## Administrador legado do EasyPanel

O login definido por `ADMIN_USER` e `ADMIN_PASSWORD` é um acesso legado baseado em variável de ambiente. A aplicação não altera variáveis do EasyPanel.

Para ter senha alterável dentro da plataforma:

1. entre com o administrador legado;
2. abra `/admin/users.php`;
3. crie um usuário com perfil `Administrador`;
4. saia e entre com esse novo usuário;
5. altere a senha pelo menu `Alterar senha` ou em `Editar acesso`.

Depois de confirmar o novo administrador, remova ou altere o `ADMIN_PASSWORD` legado no EasyPanel para não manter um acesso paralelo desnecessário.

## Arquivos principais

```text
database/034_user_access_management.sql
src/access.php
src/auth.php
public/activate-account.php
public/change-password.php
public/portal/profile.php
public/admin/users.php
public/admin/user-edit.php
public/admin/accesses.php
public/student.php
```

## Observação

Nenhum workflow do n8n precisa ser importado nesta versão. A criação automática de acesso continua usando o mesmo fluxo, mas novos usuários passam a receber um nome sugerido baseado no nome do aluno em vez de usar somente o telefone.
