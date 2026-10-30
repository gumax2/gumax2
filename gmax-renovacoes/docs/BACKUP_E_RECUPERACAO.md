# Backup e recuperação

## A. Antes de instalar (obrigatório)

1. **Banco completo:** cPanel → **Backup** → "Fazer backup de um banco de dados MySQL" → clique no banco do painel e guarde o `.sql.gz`. (Alternativa: phpMyAdmin → banco → Exportar → Rápido → SQL.)
2. **Arquivos do painel:** Gerenciador de Arquivos → `public_html/painel` → Compactar → baixe o ZIP.
3. Guarde os dois arquivos fora do servidor (no computador/nuvem). Anote data e hora.

## B. Backup do módulo (rotina)

```bash
php ~/gmax-renovacoes/bin/renovacoes-admin.php backup
```
Gera `backup-gr-AAAAMMDD-HHMMSS.sql` (permissão 600) com **somente** as tabelas `gr_*` (configurações, autorizações, fila e histórico). Copie para fora do servidor. O backup do banco completo do cPanel também inclui essas tabelas.

## C. Recuperação em níveis

| Nível | Situação | O que fazer | Efeito nos dados do painel |
|---|---|---|---|
| **1 — Pausar** (segundos) | algo estranho nos envios | Aba Configurações → **Pausa global** | nenhum |
| **2 — Parar o agendador** (1 min) | quer interromper tudo | cPanel → Cron Jobs → excluir a linha do `renovacoes-cron.php` | nenhum |
| **3 — Desligar a aba** (2 min) | problema na página | renomear `public_html/painel/renovacoes.php`, `renovacoes-api.php` e `renovacoes-webhook.php` (ex.: acrescentar `.off`) | nenhum |
| **4 — Remover o módulo** | desistir da instalação | nível 2 + apagar os arquivos públicos `renovacoes*` e a pasta `renovacoes-assets/` + apagar `/home/USUARIO/gmax-renovacoes/` + (opcional) executar `sql/rollback_remover_modulo.sql` no phpMyAdmin | nenhum: só tabelas `gr_*` são apagadas |
| **5 — Restaurar backup** | só se algo fora do módulo tiver sido afetado | restaurar o `.sql.gz` e o ZIP do passo A | volta ao ponto do backup (perde alterações feitas depois dele) |

Como o módulo **não escreve** nas tabelas do painel (verificado por teste automático), os níveis 1–4 nunca afetam clientes, pacotes, recebimentos, custos, relatórios, exportações ou a lixeira de clientes.

## D. Restaurar só o módulo

Para voltar configurações/autorizações/histórico do módulo a partir do backup do item B: phpMyAdmin → Importar o `backup-gr-....sql`. Ele usa `CREATE TABLE IF NOT EXISTS` e `INSERT IGNORE`, então não duplica linhas existentes. Para uma restauração exata, execute antes o `rollback_remover_modulo.sql` (apaga só `gr_*`) e depois importe o backup.
