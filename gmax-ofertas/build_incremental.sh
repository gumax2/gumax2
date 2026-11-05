#!/usr/bin/env bash
# Pacote INCREMENTAL: roda todos os testes e empacota só os arquivos alterados/novos desde BASE_COMMIT.
set -euo pipefail
cd "$(dirname "$0")"
NAME="${NAME:-GMAX_Ofertas_3.1.9_incremental}"
BASE_COMMIT="${BASE_COMMIT:-5b1fc07}"   # 5b1fc07 = 3.1.8 instalada; da82f0e = 3.1.7; be35b8b = 3.1.6; 72a05b7 = 3.1.5; 16c081f = 3.1.4; 592ed19 = 3.1.3; 645659a = 3.1.1; cd8ac74 = 3.0.2
OUT="dist-inc/$NAME"
rm -rf dist-inc && mkdir -p "$OUT"
find src -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors' || true
set +e
php -d allow_url_fopen=0 tests/run.php > dist-inc/t-unidade.txt 2>&1; U=$?
php tests/http_test.php > dist-inc/t-http.txt 2>&1; H=$?
php tests/compat_test.php > dist-inc/t-compat.txt 2>&1; C=$?
php -d allow_url_fopen=0 tests/links_test.php > dist-inc/t-links.txt 2>&1; L=$?
php -d allow_url_fopen=0 tests/automacoes_test.php > dist-inc/t-automacoes.txt 2>&1; A=$?
php -d allow_url_fopen=0 tests/oauth_test.php > dist-inc/t-oauth.txt 2>&1; O=$?
php -d allow_url_fopen=0 tests/execucoes_test.php > dist-inc/t-execucoes.txt 2>&1; E=$?
php -d allow_url_fopen=0 tests/shopee_test.php > dist-inc/t-shopee.txt 2>&1; S=$?
php -d allow_url_fopen=0 tests/conferencia_test.php > dist-inc/t-conferencia.txt 2>&1; K=$?
php -d allow_url_fopen=0 tests/calculadora_test.php > dist-inc/t-calculadora.txt 2>&1; Q=$?
php -d allow_url_fopen=0 tests/ranking_ml_test.php > dist-inc/t-ranking-ml.txt 2>&1; R=$?
php -d allow_url_fopen=0 tests/candidatos_ml_test.php > dist-inc/t-candidatos-ml.txt 2>&1; M=$?
php tests/ui_test.php > dist-inc/t-interface.txt 2>&1; I=$?
set -e
tail -qn1 dist-inc/t-*.txt
php tools/relatorio_testes.php > docs/TESTES.md
FILES=$(cd .. && git diff --name-only "$BASE_COMMIT" -- gmax-ofertas/src/gmax-auto; git ls-files --others --exclude-standard gmax-ofertas/src/gmax-auto)
FILES=$(echo "$FILES" | sed 's#^gmax-ofertas/src/gmax-auto/##' | sort -u)
( cd src/gmax-auto && zip -q -X "../../$OUT/${NAME}_arquivos.zip" $FILES )
{
  echo "$NAME (sobre o commit $BASE_COMMIT) — MANIFESTO ($(date -u +%Y-%m-%dT%H:%MZ))"
  echo "Destino: /home1/gust1922/gmaxcanais.com.br/gmax-auto/"
  echo
  for f in $FILES; do
    if (cd .. && git cat-file -e "$BASE_COMMIT:gmax-ofertas/src/gmax-auto/$f" 2>/dev/null); then tipo="ATUALIZA"; else tipo="NOVO    "; fi
    printf '  %s  %s  %s\n' "$tipo" "$(sha256sum "src/gmax-auto/$f" | cut -c1-16)" "$f"
  done
  echo
  echo "BANCO: migração 004 (só cria gmax3_ml_candidates e a tarefa candidatos_ml INATIVA; backup automático; aplicar em Sistema → MIGRAR). ARQUIVOS ANTIGOS (app.php, index.php, cron.php, publicador...): não tocados."
  echo "ENVIOS: nenhum habilitado. Cron e credenciais: não alterados."
} > "$OUT/MANIFESTO.txt"
cp docs/ATUALIZACAO_3.1.9_CANDIDATOS_MERCADOLIVRE.md "$OUT/00_LEIA_PRIMEIRO_3.1.9.md"
cp docs/SOLICITACAO_SUPORTE_MERCADOLIVRE.md "$OUT/"
mkdir -p "$OUT/capturas" && cp docs/capturas/3.1.5/*candidatos*.png "$OUT/capturas/"
cp docs/TESTES.md "$OUT/TESTES.md"
( cd dist-inc && zip -q -X -r "$NAME.zip" "$NAME" )
ls -la dist-inc
[ $U -eq 0 ] && [ $H -eq 0 ] && [ $C -eq 0 ] && [ $L -eq 0 ] && [ $A -eq 0 ] && [ $O -eq 0 ] && [ $E -eq 0 ] && [ $S -eq 0 ] && [ $K -eq 0 ] && [ $I -eq 0 ] && [ $Q -eq 0 ] && [ $R -eq 0 ] && [ $M -eq 0 ] || { echo "ATENÇÃO: há testes falhando"; exit 1; }
