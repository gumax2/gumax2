#!/usr/bin/env bash
# Gera o ZIP incremental de instalação (só arquivos novos; nenhum segredo).
set -euo pipefail
cd "$(dirname "$0")"
VER=$(php -r 'require "src/privado/gmax-renovacoes/bootstrap.php"; echo GR_VERSION;')
NAME="GMAX_Renovacoes_${VER}_incremental"
STAGE=$(mktemp -d)
OUT="$STAGE/$NAME"
mkdir -p "$OUT"

php tests/gerar_sql.php >/dev/null

cp -r src/privado "$OUT/privado"
cp -r src/publico "$OUT/publico"
cp -r sql "$OUT/sql"
mkdir -p "$OUT/docs"
cp docs/*.md "$OUT/docs/"
[ -d docs/capturas ] && cp -r docs/capturas "$OUT/docs/capturas"
cp LEIA_PRIMEIRO.md "$OUT/"
cp -r patch-painel "$OUT/patch-painel"

# Nunca empacotar segredos nem logs.
rm -f "$OUT/privado/gmax-renovacoes/config.php"
find "$OUT/privado/gmax-renovacoes/logs" -type f ! -name '.htaccess' ! -name '.gitignore' -delete
if grep -rIl --exclude=config.example.php -E 'EAA[A-Za-z0-9]{20,}' "$OUT" ; then echo "ERRO: possível token no pacote"; exit 1; fi

{
  echo "GMAX Renovações $VER — manifesto ($(date -u +%Y-%m-%dT%H:%MZ))"
  echo "Todos os arquivos são NOVOS. Nenhum arquivo existente do painel é substituído. .htaccess do painel não é alterado."
  echo
  (cd "$OUT" && find . -type f ! -name MANIFESTO.txt | sort | while read -r f; do echo "$(sha256sum "$f" | cut -c1-16)  $f"; done)
} > "$OUT/MANIFESTO.txt"

mkdir -p dist
rm -f "dist/$NAME.zip"
(cd "$STAGE" && zip -qr -X "$OLDPWD/dist/$NAME.zip" "$NAME")
rm -rf "$STAGE"
echo "dist/$NAME.zip"
