#!/usr/bin/env sh
set -eu

first_line="$(awk 'NF {print; exit}' Dockerfile)"
case "$first_line" in
  FROM\ *) ;;
  *)
    echo "ERRO: Dockerfile inválido. A primeira instrução deve começar com FROM."
    echo "Encontrado: $first_line"
    exit 1
    ;;
esac

if grep -Eq '^# RS English|^A tabela |^Versão ' Dockerfile; then
  echo "ERRO: o Dockerfile parece conter texto de README."
  exit 1
fi

echo "OK: Dockerfile e .dockerignore parecem válidos para o deploy."
