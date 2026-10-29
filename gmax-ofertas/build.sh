#!/usr/bin/env bash
# Gera o pacote único de atualização P0: roda os testes, registra resultados e empacota.
set -euo pipefail
cd "$(dirname "$0")"
NAME="GMAX_Ofertas_3.0.2_P0"
DIST="dist/$NAME"
rm -rf dist && mkdir -p "$DIST/desenvolvimento"

echo "== Lint"
find src -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors' || true

echo "== Testes de unidade/integração"
set +e
php -d allow_url_fopen=0 tests/run.php > dist/saida-testes-unidade.txt 2>&1; U=$?
php tests/http_test.php > dist/saida-testes-http.txt 2>&1; H=$?
php tests/compat_test.php > dist/saida-testes-compat.txt 2>&1; C=$?
set -e
tail -1 dist/saida-testes-unidade.txt; tail -1 dist/saida-testes-http.txt; tail -1 dist/saida-testes-compat.txt
php tools/relatorio_testes.php > docs/TESTES.md

echo "== Pacote"
( cd src && find gmax-auto -type f | sort > ../dist/lista.txt )
( cd src/gmax-auto && zip -q -X -r "../../$DIST/GMAX_Ofertas_3.0.2_P0_arquivos.zip" painel.php gmax-admin-cli.php gmax-diagnostico-esquema.php gmax-lib gmax-assets )
{
  echo "GMAX Ofertas 3.0 P0 — MANIFESTO ($(date -u +%Y-%m-%dT%H:%MZ))"
  echo
  echo "ADICIONAR em /home1/gust1922/gmaxcanais.com.br/gmax-auto/ (arquivos NOVOS):"
  ( cd src && while read -r f; do printf '  %s  %s\n' "$(sha256sum "$f" | cut -c1-16)" "${f#gmax-auto/}"; done < ../dist/lista.txt )
  echo
  echo "OPCIONAL (não instalar agora): opcional/gmax-publicador-cli.php  $(sha256sum src/opcional/gmax-publicador-cli.php | cut -c1-16)"
  echo "  substitui gmax-publicador-cli.php SOMENTE no passo 9, com backup .bak"
  echo
  echo "MODIFICAR: nenhum arquivo existente."
  echo "SUBSTITUIR: nenhum arquivo existente."
  echo
  echo "PRESERVAR (não tocar): app.php, index.php, cron.php, .htaccess, gmax-publicador-cli.php,"
  echo "  mercadolivre.php, gmax-ml-importar-cli.php, storage/, gmax-secrets/, Crons e credenciais."
  echo
  echo "BANCO: migração 001 cria tabelas gmax3_* e copia (não move) os produtos de offers e gmax_ml_offers."
  echo "  Tabelas offers, slots, users, gmax_ml_offers, gmax_dispatches: inalteradas."
  echo
  echo "ENVIOS: nenhum habilitado. Fila pausada; automação comercial desativada; nenhum Cron criado."
} > "$DIST/MANIFESTO.txt"
mkdir -p "$DIST/opcional" && cp src/opcional/gmax-publicador-cli.php "$DIST/opcional/"
cp docs/MIGRACAO_HOSTGATOR.md docs/COMPARATIVO_PRODUCAO.md docs/INSTALACAO.md docs/ROLLBACK.md docs/DIAGNOSTICO.md docs/LIMITACOES.md docs/TESTES.md "$DIST/"
cp docs/LEIA_PRIMEIRO.md "$DIST/00_LEIA_PRIMEIRO.md"
mkdir -p "$DIST/capturas" && cp docs/capturas/*.png "$DIST/capturas/" 2>/dev/null || true
cp -r tests referencia build.sh tools "$DIST/desenvolvimento/"
cp -r src "$DIST/desenvolvimento/src"
mkdir -p "$DIST/desenvolvimento/docs"
rm -f "$DIST/desenvolvimento/tests/"*.json
( cd dist && zip -q -X -r "$NAME.zip" "$NAME" )
rm -f dist/lista.txt
ls -la dist
[ $U -eq 0 ] && [ $H -eq 0 ] && [ $C -eq 0 ] || { echo "ATENÇÃO: há testes falhando"; exit 1; }
