# Backup e recuperação

> Backups contêm dados reais de clientes e financeiro. **Nunca** coloque um backup no repositório Git, no ZIP do módulo ou em mensagens. Guarde fora do servidor (computador/nuvem pessoal), com acesso restrito.

## A. Antes de instalar (obrigatório)

1. **Dump SQL completo do banco do painel** (`gust1922_gmaxpainel`), por um destes caminhos:
   - cPanel → **Backup** → "Fazer backup de um banco de dados MySQL" → clique no banco → baixa `.sql.gz`; ou
   - phpMyAdmin → selecione o banco → **Exportar** → Método "Personalizado" → todas as tabelas, Formato SQL, "Adicionar CREATE TABLE" e "INSERT" marcados → Executar; ou
   - Terminal (a senha é pedida na tela, não fica no histórico):
     ```bash
     mysqldump --single-transaction --routines --default-character-set=utf8mb4 -u USUARIO_DO_BANCO -p gust1922_gmaxpainel > ~/backup-gmaxpainel-$(date +%Y%m%d-%H%M).sql
     chmod 600 ~/backup-gmaxpainel-*.sql
     ```
2. **Arquivos do painel:** Gerenciador de Arquivos → `public_html/painel` → Compactar → baixar o ZIP.
3. **Conferir o backup:** o arquivo `.sql` não pode estar vazio e deve conter `CREATE TABLE` de `clients`, `client_plans`, `client_exclusions`, `entries`, `entry_plans`, `settings` e `admins`.
4. **Retrato de integridade** (`integridade-antes.json`, passo 4 da instalação). Ele **não** substitui o backup. Serve para comparar depois.
5. Baixe tudo para fora do servidor e apague as cópias de `~/` depois da instalação validada.

## B. Prova de preservação

`integridade` grava, para cada tabela do painel:
- número de linhas;
- hash dos IDs, da estrutura e do conteúdo completo;
- totais de receita, custo, despesa, lucro e da soma de preços/custos dos pacotes.

Não grava nomes, telefones nem códigos. `integridade-comparar antes.json depois.json` precisa terminar com "dados do painel preservados". Qualquer diferença (1 centavo, 1 linha, 1 coluna) aparece como DIFERENTE.

## C. Recuperação em níveis

| Nível | Situação | O que fazer | Efeito nos dados do painel |
|---|---|---|---|
| 1 — Pausar | algo estranho | Aba Configurações → **Pausa global** (ou desmarcar Módulo ativo) | nenhum |
| 2 — Parar o agendador | interromper tudo | Cron Jobs → excluir a linha do `renovacoes-cron.php` | nenhum |
| 3 — Tirar do menu | link ou aba com problema | `php bin/renovacoes-admin.php menu remover ~/public_html/painel/index.html` (ou restaurar a cópia `index.html.antes-renovacoes-*`) | nenhum |
| 4 — Remover o módulo | desistir | níveis 2 e 3 + apagar `public_html/painel/renovacoes*` e `renovacoes-assets/` + apagar `~/gmax-renovacoes/` + (opcional) `sql/rollback_remover_modulo.sql` no phpMyAdmin (apaga **só** `gr_*`) | nenhum |
| 5 — Restaurar backup | **somente** se o comparativo mostrar diferença nas tabelas do painel | restaurar o dump do passo A (phpMyAdmin → Importar) e o ZIP da pasta `painel` | volta ao ponto do backup; perde alterações feitas depois dele |

O módulo não grava nas tabelas do painel. Isso é verificado por teste automático e, na instalação, pelo comparativo de integridade. Por isso os níveis 1 a 4 não afetam clientes, pacotes, vencimentos, recebimentos, custos, despesas, relatórios, exportações ou a lixeira.

## D. Backup só do módulo

```bash
php ~/gmax-renovacoes/bin/renovacoes-admin.php backup
```
Exporta só as tabelas `gr_*`: autorizações, fila, histórico e configurações. O arquivo é gravado com permissão 600. Importá-lo de novo usa `CREATE TABLE IF NOT EXISTS` e `INSERT IGNORE`, então não duplica linhas.
