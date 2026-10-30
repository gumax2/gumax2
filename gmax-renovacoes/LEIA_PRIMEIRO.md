# GMAX Renovações 1.1.0 — lembretes de renovação por WhatsApp

Módulo adicional para o painel **GMAX — Central de Gestão** (`/painel/`), integrado à base de 15/10/2026 (`GMAX_Base_Painel_Para_Claude.zip`). Ele identifica cada **ponto/pacote** (`client_plans`) perto do vencimento, pelo vencimento próprio de cada um. O envio dos lembretes pelo WhatsApp parte do servidor.

**Pacote para REVISÃO. Não instale antes da revisão. O envio real está desligado.**

## Situação

| Item | Estado |
|---|---|
| Leitura de clientes e pacotes reais (`clients`, `client_plans.due_date`, `client_exclusions`, `price_cents`) | **pronto**, testado em banco descartável com a estrutura real |
| Sessão do painel (`gmax_session`, `admin`, `csrf`, expiração 3600 s, HTTPS) | **pronto**, testado com o `api.php` real em servidor local |
| Preservação dos dados (clientes, pacotes, vencimentos, preços, custos, financeiro, exclusões) | **verificada em banco descartável** com dados fictícios. **Na produção, só depois do comparativo de integridade** do passo 5 da instalação |
| Regras, fila, agendador, revalidação, webhook, aba Renovações | **pronto e testado** |
| Envio pelo WhatsApp | **simulado**. O simulado **não prova entrega real** |
| Conector oficial (WhatsApp Cloud API) | escrito, **desligado**. Faltam conta, credenciais, modelos aprovados e sua autorização |
| Consentimento | **nenhum cliente existente é autorizado automaticamente** |

## O que muda no servidor

- **Novo:** `/home1/gust1922/gmax-renovacoes/` (privado), 3 páginas e assets em `public_html/painel/`, 8 tabelas `gr_*`.
- **Alterado:** só o `painel/index.html`, onde entram 2 linhas: o link do menu e uma folha de estilo. O comando que aplica confere o arquivo atual, faz cópia e pode desfazer (`patch-painel/`).
- **Não alterado:** `api.php`, `app.js`, CSS do painel, config do painel, `.htaccess`, tabelas existentes, outros projetos.

## Conteúdo

| Caminho | O que é |
|---|---|
| `privado/gmax-renovacoes/` | biblioteca, config de exemplo (sem segredos), Cron e CLI (`diagnostico`, `integridade`, `migrar`, `menu`, `backup`) |
| `publico/painel/` | `renovacoes.php`, `renovacoes-api.php`, `renovacoes-webhook.php`, `renovacoes-assets/` |
| `patch-painel/` | a alteração do `index.html`: diff + instruções |
| `sql/001_inicial.sql` | migração só com `CREATE TABLE IF NOT EXISTS gr_*` e `INSERT IGNORE INTO gr_*` |
| `sql/rollback_remover_modulo.sql` | remove só `gr_*` |
| `docs/INSTALACAO.md` | backup → retrato antes → migração → retrato depois → comparativo → menu → Cron |
| `docs/BACKUP_E_RECUPERACAO.md` | backup SQL completo (fora do Git/ZIP) e reversão em níveis |
| `docs/DIAGNOSTICO.md` | base, mapeamento e arquitetura |
| `docs/WHATSAPP_API.md` | provedor, custos, coexistência, modelos, consentimento |
| `docs/TESTES_RESULTADO.md` | testes executados (gerado automaticamente) |
| `docs/REVISAO_SEGURANCA.md` | revisões de segurança (2 rodadas) |
| `docs/PENDENCIAS.md` | o que realmente falta para ativar |
| `docs/capturas/` | telas com dados fictícios |
| `MANIFESTO.txt` | arquivos e hashes |

## Desenvolvimento (fora do ZIP)

Os testes ficam no repositório e rodam em bancos descartáveis `grtest_*`:
```bash
GR_PAINEL_BASE=/caminho/gmax-base-painel php tests/run.php
```
Para gerar o pacote: `./build.sh`.
