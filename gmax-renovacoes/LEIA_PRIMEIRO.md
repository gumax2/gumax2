# GMAX Renovações 1.0.0 — lembretes de renovação por WhatsApp

Módulo adicional para o painel **GMAX — Central de Gestão** (`/painel/`). Ele identifica os **pacotes** perto do vencimento, cada um com a sua própria data, e envia lembretes pelo WhatsApp a partir do servidor. Os envios rodam pelo Cron, mesmo com computador e celular desligados.

## Situação

| Item | Estado |
|---|---|
| Regras, fila, agendador, revalidação, deduplicação, webhook, aba Renovações | **pronto e testado** com dados fictícios |
| Envio pelo WhatsApp | **simulado** (provedor simulado, nenhuma chamada de rede) |
| Conector da API oficial (Cloud API da Meta) | **pronto, mas desligado**; falta conta, credenciais e modelos aprovados |
| Integração com os arquivos reais do painel | **pendente**: os arquivos do painel não estavam disponíveis (ver `docs/PENDENCIAS.md`) |

Nada é enviado a clientes enquanto você não fizer as quatro coisas abaixo:
1. configurar o provedor real no servidor;
2. colocar o modo Real;
3. confirmar a chave Pix;
4. desmarcar "somente números de teste".

## Conteúdo

| Caminho | O que é |
|---|---|
| `privado/gmax-renovacoes/` | biblioteca. Vai para `/home/USUARIO/gmax-renovacoes` (**fora** de `public_html`) |
| `publico/painel/` | 3 páginas + assets. Vão para `public_html/painel/` (somente arquivos novos) |
| `sql/001_inicial.sql` | migração (alternativa ao comando `migrar`), aditiva e reaplicável |
| `sql/rollback_remover_modulo.sql` | remove só as tabelas `gr_*` |
| `docs/DIAGNOSTICO.md` | diagnóstico da base e arquitetura |
| `docs/INSTALACAO.md` | passo a passo: backup, envio, config, migração, Cron, simulação, envio real |
| `docs/WHATSAPP_API.md` | provedor, custos, coexistência, modelos, consentimento, webhook |
| `docs/BACKUP_E_RECUPERACAO.md` | backup e reversão em níveis |
| `docs/TESTES_RESULTADO.md` | relatório dos testes **executados** (gerado automaticamente) |
| `docs/REVISAO_SEGURANCA.md` | revisão de segurança |
| `docs/PENDENCIAS.md` | o que depende de você |
| `docs/capturas/` | telas (celular e computador) com dados fictícios |
| `MANIFESTO.txt` | lista de arquivos com hash |

## Ordem

`docs/PENDENCIAS.md` → `docs/BACKUP_E_RECUPERACAO.md` (parte A) → `docs/INSTALACAO.md`.

## Desenvolvimento

- Testes: `php tests/run.php` (precisa de MySQL/MariaDB local com usuário `grtest`; cria um banco temporário `grtest_*` por teste).
- Pacote: `./build.sh` → `dist/GMAX_Renovacoes_<versão>_incremental.zip`.
