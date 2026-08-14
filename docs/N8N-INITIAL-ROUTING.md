# Roteamento inicial no n8n

Antes do Teacher IA, adicione um nó Code e um Switch.

Código sugerido:

```javascript
const status = $json.student?.diagnostic_status ?? 'pending';
const step = Number($json.student?.diagnostic_step ?? 0);
const level = $json.student?.overall_level ?? 'A1';

let flow = 'teacher';

if (status === 'pending' && step === 0) {
  flow = 'welcome';
} else if (status !== 'completed') {
  flow = 'diagnostic';
}

return [{
  json: {
    ...$json,
    flow,
    language_support:
      level === 'PRE-A1' || level === 'A0'
        ? 'portuguese'
        : level === 'A1'
          ? 'adaptive'
          : 'english'
  }
}];
```

No Switch crie:
- welcome
- diagnostic
- teacher

### welcome
Enviar a primeira mensagem em português.
Não chamar o Teacher normal.

### diagnostic
Usar o prompt de diagnóstico adaptativo.

### teacher
Usar o Teacher normal com nível e preferências.
