# Diagnóstico da base e arquitetura

## 1. Diagnóstico da base

### 1ª etapa (30/10/2026, manhã)
O repositório `gumax2/gumax2` não continha o painel financeiro (só o README do perfil, o projeto **GMAX Ofertas** — outro projeto, não tocado — e um vídeo). O módulo foi construído com um adaptador configurável.

### 2ª etapa (30/10/2026) — base recebida: `GMAX_Base_Painel_Para_Claude.zip`
Origem informada: entrega de **15/10/2026** (`ORIGEM.json`: `production_download: false`). **Não é cópia da produção de hoje**; por isso nenhum arquivo do painel é substituído: a única alteração é aplicada sobre o arquivo real do servidor com conferência prévia (ver `patch-painel/LEIA.md`).

| Item | O que a base mostra | Como o módulo usa |
|---|---|---|
| Banco | MySQL, credenciais em `/home1/gust1922/gmax-painel/private/config.php` (`dsn`, `db_user`, `db_password`) | o módulo **lê esse mesmo arquivo** (`db.config_painel`); nenhuma senha é copiada |
| Clientes | `clients` (id **VARCHAR(36)** texto, name, phone, status active/paused/archived) | leitura; IDs tratados como texto |
| Exclusão | `client_exclusions(client_id)` — cliente excluído = existe linha | cliente excluído nunca é selecionado nem listado; o módulo não restaura |
| Pacotes | `client_plans` (label, points, service, months 1/3/6/12, **price_cents** total, **due_date VARCHAR 'YYYY-MM-DD'**, status) | **fonte dos avisos**: cada pacote com seu `due_date` |
| Legado | `clients.due_date`, `clients.price_cents` etc. | **ignorados** (o `api.php` calcula um resumo; a verdade é `client_plans`) |
| Financeiro | `entries` (revenue/cost/expense, amount_cents), `entry_plans` (group → plano), `settings` | **nunca escrito**; só lido pela ferramenta de integridade (totais) |
| Renovação | `api.php action=entry` com `new_due_date` atualiza só o `due_date` daquele pacote | o módulo detecta a mudança de `due_date` e cancela os avisos daquele pacote/ciclo. Recebimento **sem** nova data **não** é tratado como renovação |
| Login | sem arquivo separado: `index.html` (#auth-form) + `app.js` + `api.php action=login` | reaproveita a sessão `gmax_session`, `$_SESSION['admin']`, CSRF `$_SESSION['csrf']`, expiração 3600 s (`last`), cookie path=/ HttpOnly SameSite=Strict, HTTPS obrigatório. Não inclui o `api.php` |
| Menu | `index.html` `<nav>` com botões `data-page`; `app.js` controla | acrescenta um `<a>` "Renovações" (sem `data-page`) + uma folha CSS própria; `app.js` não muda |
| PWA | `manifest.webmanifest` escopo `./`, sem service worker | `renovacoes.php` fica em `/painel/` (mesmo escopo); nada é armazenado offline |
| Integração WhatsApp existente | nenhuma | — |
| Consentimento | não existe na base | guardado em `gr_contatos`; **ninguém é autorizado automaticamente** |

Valores de exemplo do `app.js` (`seed()`, modo `?demo=1`) são fictícios e não são usados nem importados.

## 2. Arquitetura

```
/home/USUARIO/
├── gmax-renovacoes/            ← PRIVADO (fora de public_html)
│   ├── config.php              ← credenciais e mapeamento (chmod 600) — você cria a partir do exemplo
│   ├── bootstrap.php
│   ├── lib/                    ← regras, fila, provedores, webhook
│   ├── migrations/             ← migrações versionadas
│   ├── cron/renovacoes-cron.php← agendador (Cron do cPanel a cada 15 min)
│   ├── bin/renovacoes-admin.php← diagnóstico, migração, backup
│   └── logs/                   ← log sem segredos
└── public_html/painel/         ← PÚBLICO (só 3 arquivos novos + assets)
    ├── renovacoes.php          ← a aba "Renovações"
    ├── renovacoes-api.php      ← JSON (login + CSRF)
    ├── renovacoes-webhook.php  ← callbacks da Meta (assinatura HMAC)
    └── renovacoes-assets/      ← CSS e JS puros
```

### Tabelas novas (prefixo `gr_`, nenhuma tabela existente é alterada)

| Tabela | Função |
|---|---|
| `gr_migracoes` | versões aplicadas |
| `gr_configuracoes` | textos, horários, Pix, modo, pausa (nada secreto) |
| `gr_contatos` / `gr_consentimento_log` | autorização, revogação e pausa por cliente, com histórico |
| `gr_lembretes` | **fila persistente**: 1 linha por `(modo, pacote, vencimento, etapa)` — chave **única** |
| `gr_mensagens` | mensagem enviada (pode agrupar vários lembretes), status, tentativas, erros |
| `gr_eventos` | linha do tempo: envio, callbacks (com chave de deduplicação), ações manuais |
| `gr_execucoes` | registro de cada execução do agendador |

### Fluxo de cada execução (cron a cada 15 min)

1. **Trava** `GET_LOCK` do MySQL: uma segunda execução simultânea sai sem fazer nada.
2. **Recupera interrompidos:** mensagem presa em "enviando" vira **Resultado incerto** (nunca é reenviada sozinha).
3. **Expira atrasados:** o que não saiu no dia previsto é cancelado (não manda aviso velho).
4. **Varredura de renovações:** se o vencimento do pacote mudou, cancela os avisos **daquele pacote e ciclo**; os outros pacotes do cliente não são afetados.
5. **A partir do horário (09:00):** gera os lembretes do dia — pacotes cujo vencimento é *hoje+3*, *hoje* ou *ontem* (sem renovação). Só o dia corrente: ativar o módulo **não** dispara lembretes antigos.
6. **Dentro da janela (até 20:00) e sem pausa:** agrupa por *cliente + vencimento + etapa* (pacotes na mesma data → 1 mensagem), **revalida** com dados frescos, reivindica a mensagem de forma atômica e envia.
7. Resultado: *Aceito pelo provedor* / falha temporária (nova tentativa em 5, 10, 20 min, limitada) / *Falhou* / *Resultado incerto*. *Entregue* e *Lido* só chegam pelo webhook.

### Regras de elegibilidade (geração **e** revalidação imediatamente antes do envio)

Não envia se: cliente excluído (`client_exclusions`) ou inexistente, cliente `paused`/`archived`, pacote `paused`/`archived`, **vencimento alterado** (renovado), valor zerado, telefone inválido, **sem autorização** ou autorização revogada, telefone mudou depois da autorização, cliente pausado no módulo, ou — no modo real com "somente números de teste" — número fora da lista interna.

### Valores

`client_plans.price_cents` é o **total do pacote/ciclo**. A mensagem informa esse valor sem multiplicar pelos pontos (4 pontos por R$ 120 → "R$ 120,00"). Grupo: valor de cada pacote + total. `months` 3/6/12 → "trimestral/semestral/anual" com o valor do ciclo.

O módulo **nunca** registra receita, nunca prorroga vencimento e não presume confirmação de Pix. Renovação só é reconhecida quando **você** atualiza o vencimento no painel.

### Provedores

- `SimulatedProvider` — padrão. Não faz nenhuma chamada de rede.
- `MetaCloudProvider` — WhatsApp Cloud API oficial, envio de **modelo aprovado** (`type: template`). Só é usado quando: `driver = meta_cloud` no `config.php` **e** modo "Real" na aba **e** Pix confirmado **e** nomes de modelos preenchidos. Caso contrário o sistema fica em simulação, com faixa amarela na tela.

### Status das mensagens

| Status | Quando |
|---|---|
| Aguardando envio | criada, ou aguardando nova tentativa |
| Aceito pelo provedor | a API devolveu o ID da mensagem (não significa entregue) |
| Entregue | callback `delivered` da Meta |
| Lido | callback `read` (só se o cliente tiver confirmação de leitura ligada) |
| Falhou | rejeição definitiva, limite de tentativas, ou callback `failed` |
| Cancelado | renovado/excluído/sem autorização antes do envio, ou cancelado por você |
| Resultado incerto | timeout depois do envio, erro 5xx, processo interrompido — **confira no WhatsApp** antes de reenviar |
| Enviado manualmente | você registrou que mandou pelo aplicativo |

**O que "sem duplicidade" significa — e o que não significa:** o sistema garante que ele próprio não dispara de novo o mesmo aviso (mesmo pacote + vencimento + etapa), nem por agendamento repetido nem por dois processos simultâneos (testado). Ele **não** pode garantir o que aconteceu do lado do provedor quando a resposta se perde: nesse caso a mensagem fica **Resultado incerto** até ser reconciliada (callback da Meta com a referência, ou sua conferência no WhatsApp). Se você escolher "Reenviar" num incerto, pode haver duplicata — por isso a tela pede confirmação. O **modo simulação não prova entrega**: só prova que a regra escolheu o cliente, o texto e o momento certos.

Callbacks repetidos são ignorados (chave de deduplicação); fora de ordem não fazem o status regredir (ex.: "entregue" depois de "lido"); "falhou" depois de "entregue" é ignorado. Callback que chega antes de gravarmos o ID é guardado e aplicado em seguida. Com `biz_opaque_callback_data`, um envio "incerto" é esclarecido automaticamente se a Meta mandar o status.
