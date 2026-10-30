#!/usr/bin/env bash
# Pacote INCREMENTAL 3.0.3 (sobre a 3.0.2): roda todos os testes e empacota só os arquivos alterados/novos.
set -euo pipefail
cd "$(dirname "$0")"
NAME="${NAME:-GMAX_Ofertas_3.1_incremental}"
BASE_COMMIT="cd8ac74"   # pacote 3.0.2
OUT="dist-inc/$NAME"
rm -rf dist-inc && mkdir -p "$OUT"
find src -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors' || true
set +e
php -d allow_url_fopen=0 tests/run.php > dist-inc/t-unidade.txt 2>&1; U=$?
php tests/http_test.php > dist-inc/t-http.txt 2>&1; H=$?
php tests/compat_test.php > dist-inc/t-compat.txt 2>&1; C=$?
php -d allow_url_fopen=0 tests/links_test.php > dist-inc/t-links.txt 2>&1; L=$?
php -d allow_url_fopen=0 tests/automacoes_test.php > dist-inc/t-automacoes.txt 2>&1; A=$?
set -e
tail -qn1 dist-inc/t-*.txt
php tools/relatorio_testes.php > docs/TESTES.md
FILES=$(cd .. && git diff --name-only "$BASE_COMMIT" -- gmax-ofertas/src/gmax-auto; git ls-files --others --exclude-standard gmax-ofertas/src/gmax-auto)
FILES=$(echo "$FILES" | sed 's#^gmax-ofertas/src/gmax-auto/##' | sort -u)
( cd src/gmax-auto && zip -q -X "../../$OUT/${NAME}_arquivos.zip" $FILES )
{
  echo "$NAME (sobre 3.0.2; inclui 3.0.3) — MANIFESTO ($(date -u +%Y-%m-%dT%H:%MZ))"
  echo "Destino: /home1/gust1922/gmaxcanais.com.br/gmax-auto/"
  echo
  for f in $FILES; do
    if (cd .. && git cat-file -e "$BASE_COMMIT:gmax-ofertas/src/gmax-auto/$f" 2>/dev/null); then tipo="ATUALIZA"; else tipo="NOVO    "; fi
    printf '  %s  %s  %s\n' "$tipo" "$(sha256sum "src/gmax-auto/$f" | cut -c1-16)" "$f"
  done
  echo
  echo "BANCO: migração 002 (somente tabelas novas, backup automático antes). ARQUIVOS ANTIGOS (app.php, index.php, cron.php, publicador...): não tocados."
  echo "ENVIOS: nenhum habilitado. Cron e credenciais: não alterados."
} > "$OUT/MANIFESTO.txt"
cp docs/ATUALIZACAO_3.1_AUTOMACOES.md "$OUT/00_LEIA_PRIMEIRO_INSTALACAO.md"
cp docs/LIMITACOES_APIS.md docs/ATUALIZACAO_3.0.3_LINKS_EM_LOTE.md docs/ROLLBACK.md "$OUT/"
mkdir -p "$OUT/capturas" && cp docs/capturas/*automacoes*.png "$OUT/capturas/" 2>/dev/null || true
cp docs/TESTES.md "$OUT/TESTES.md"
( cd dist-inc && zip -q -X -r "$NAME.zip" "$NAME" )
ls -la dist-inc
[ $U -eq 0 ] && [ $H -eq 0 ] && [ $C -eq 0 ] && [ $L -eq 0 ] && [ $A -eq 0 ] || { echo "ATENÇÃO: há testes falhando"; exit 1; }
