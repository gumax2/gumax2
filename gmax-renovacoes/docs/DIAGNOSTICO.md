# Diagnóstico da base e arquitetura

## 1. Diagnóstico da base (30/10/2026)

**O que foi inspecionado:** o repositório `gumax2/gumax2`, em todas as branches.

| Branch | Conteúdo | Relação com o painel financeiro |
|---|---|---|
| `main` | Somente o `README.md` do perfil do GitHub | nenhuma |
| `gmax-ofertas-3.0-p0` | Projeto **GMAX Ofertas** (Mercado Livre, publicador, automações) | **outro projeto** — não foi usado nem alterado |
| `gumax2-patch-1-LP` | Um vídeo (`videozap`) | nenhuma |

**Conclusão:** os arquivos do painel **GMAX — Central de Gestão** (`/painel/`: clientes, pacotes, vencimentos, recebimentos, custos) **não estavam anexados nem no repositório**. Também não havia capturas de tela. Não existe nenhuma integração de WhatsApp em nenhum dos arquivos disponíveis (busca por `whatsapp`, `wa.me`, `graph.facebook`, `twilio`, `zapi`: nada no painel; o GMAX Ofertas não tem envio por WhatsApp).

**Consequência para o desenho:** o módulo foi construído para **não depender** de conhecer o código do painel:

- lê clientes e pacotes **somente para leitura**, por um **mapeamento de tabelas/colunas** no `config.php` (`esquema`);
- reaproveita o **login do painel** pela sessão PHP (chave configurável), sem criar outro login;
- não altera nenhum arquivo existente do painel, nem o `.htaccess`;
- `php bin/renovacoes-admin.php diagnostico` confere, no servidor, se as tabelas e colunas mapeadas existem.

O que precisa ser confirmado com os arquivos reais está em `PENDENCIAS.md` (nome das tabelas/colunas, chave de sessão do login, valores de status, onde colocar o link da aba).

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

Não envia se: cliente excluído/inexistente, cliente pausado/arquivado (status fora da lista `status_ativos`), pacote pausado/arquivado/excluído, **vencimento alterado** (renovado), valor zerado, telefone inválido, **sem autorização** ou autorização revogada, telefone mudou depois da autorização, cliente pausado no módulo, ou — no modo real com "somente números de teste" — número fora da lista interna.

### Valores

O preço cadastrado é o **total do pacote/ciclo**. A mensagem informa esse valor sem multiplicar pelos pontos (4 pontos por R$ 120 → "R$ 120,00"). Grupo: valor de cada pacote + total. Trimestral/anual: valor do ciclo e o nome do ciclo.

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

Callbacks repetidos são ignorados (chave de deduplicação); fora de ordem não fazem o status regredir (ex.: "entregue" depois de "lido"); "falhou" depois de "entregue" é ignorado. Callback que chega antes de gravarmos o ID é guardado e aplicado em seguida. Com `biz_opaque_callback_data`, um envio "incerto" é esclarecido automaticamente se a Meta mandar o status.
