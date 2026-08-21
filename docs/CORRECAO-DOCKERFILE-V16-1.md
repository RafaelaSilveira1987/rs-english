# Correção do Dockerfile — v16.1

O deploy falha com `unknown instruction: A` quando o arquivo `Dockerfile` contém texto do README.

Antes do commit, execute na raiz do projeto:

```bash
sh scripts/validate-deploy-files.sh
```

O primeiro conteúdo não vazio do Dockerfile deve ser:

```dockerfile
FROM php:8.3-apache
```

O arquivo `.dockerignore` deve permanecer separado.
