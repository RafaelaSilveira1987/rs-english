# Instalação — RS English v10.6

1. Substitua os arquivos do projeto pelos arquivos deste pacote.
2. Execute no Adminer, após as migrations anteriores:

```sql
-- arquivo database/029_portal_experience.sql
```

3. Faça commit e push para a branch usada pelo EasyPanel.
4. No EasyPanel, execute `Rebuild` e `Redeploy`.
5. Limpe o cache do navegador com `Ctrl + F5`.

## Arquivos de logo

- `public/assets/images/rs-english-horizontal-dark.webp`
- `public/assets/images/rs-english-horizontal-light.webp`
- `public/assets/images/rs-english-mark.webp`

## Verificações

- Professor/admin deve abrir `/index.php`.
- Aluno deve abrir `/portal/index.php`.
- A ficha individual deve abrir em `/student.php?id=UUID`.
- Preferências de conversação devem ser salvas em `/portal/onboarding.php`.
- A prática deve carregar tema, formato, correção e duração salvos.
