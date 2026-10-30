# GMAX Ofertas 3.1: Central de Automações (atualização incremental)

**Base:** GMAX 3.0.2 instalada e migrada. Este ZIP **já inclui** a 3.0.3 (links afiliados em lote), então serve tanto para quem instalou a 3.0.3 quanto para quem não instalou.
**Banco:** migração **002**, que só cria tabelas novas, com backup verificado antes.
**Envios:** **nenhum é habilitado.** As automações já vêm **pausadas e inativas**. A aprovação automática vem **desligada**. Nenhum Cron é criado sozinho.

## 1. O que a 3.1 faz

| Automação | Horário padrão (configurável) | O que faz | O que NUNCA faz |
|---|---|---|---|
| Descoberta | 07h, todos os dias | Busca ofertas nas APIs oficiais pelos termos que você cadastrar. Pula repetidos, indisponíveis e incompletos. Grava os novos como **rascunho**, com origem e data | Inventar preço, vendas ou desconto; criar link afiliado do Mercado Livre |
| Revisão de preços | 12h e 18h | Confirma preço, preço original, disponibilidade e variações pela API. Se o preço mudou, a aprovação é retirada. Se a API falhar, abre um alerta e a publicação fica bloqueada | Publicar com preço não confirmado |
| Seleção | 18h30 | Coloca as melhores ofertas na fila, respeitando o **limite diário** e um item por horário, com prioridade pela pontuação | Enviar mensagens (a fila continua com todas as travas) |
| Análise semanal | segunda, 07h30 | Revisão aprofundada de até 100 produtos, mais a saúde do catálogo | — |
| Ofertas vencidas | sexta, 07h30 | Ofertas aprovadas com preço antigo voltam para validação, e o agendamento é cancelado. Indisponíveis há mais de 7 dias são **arquivadas**. Depois, a seleção é renovada | Apagar registros (arquivar preserva histórico e auditoria) |
| Saúde do catálogo | 06h30, todos os dias | Aponta duplicidades, links ausentes ou fora do padrão e variações pendentes. Os alertas fecham sozinhos quando o problema é resolvido | Alterar preços |

**Outras funções**
- **Central de Automações:** automações ativas e pausadas, próximas e últimas execuções, encontrados, atualizados, aprovados e bloqueados, falhas de integração, histórico, indicadores, alertas, **Executar agora** e **Pausa geral**. Funciona no celular.
- **Ranking ampliado:** 10 critérios com pesos visíveis (desconto, popularidade, avaliação, qualidade dos dados, reputação, disponibilidade, comentários, faixa de preço, categoria, pagamento). Dado ausente vale zero e reduz a confiança.
- **Mensagens:** no formato que você pediu (🛍️ 🔥 ✅ 💰 💳 🛒), com **modelos por loja e categoria** editáveis no painel. Uma linha sem dado confirmado (por exemplo, "De") é omitida.
- **Fila:** limite diário por canal (padrão 6), horários distintos, duplicidade, reconciliação de envio incerto e pausa emergencial, todos mantidos da 3.0.
- **Aprovação automática:** **desligada**. Só pode ser ligada pela linha de comando, com frase de autorização, e mesmo assim só aprova ofertas com preço **confirmado por API**, link oficial, pontuação e confiança mínimas e sem nenhuma pendência.

## 2. Instalação no cPanel (cerca de 15 minutos)

1. **Backup:** painel → **Sistema** → **Criar backup agora**. Depois, no Gerenciador de Arquivos, selecione `painel.php`, `gmax-lib`, `gmax-assets` e `gmax-admin-cli.php` → **Compress** → `gmax-3.0.x-arquivos-AAAAMMDD.zip` → mova para `gmax-secrets/`.
2. **Envio:** faça upload de `GMAX_Ofertas_3.1_incremental_arquivos.zip` em `gmax-auto/` → **Extract** em `/home1/gust1922/gmaxcanais.com.br/gmax-auto/` → apague o ZIP.
3. **Proteção:** `https://gmaxcanais.com.br/gmax-auto/gmax-automacoes-cli.php` deve mostrar "Apenas CLI", e `…/gmax-lib/Gmax/Automation/Jobs.php` deve dar **403** ou abrir em branco.
4. **Migração 002:** abra o painel (ele leva direto a **Sistema → Migração pendente**). Confira **nenhuma FALHA** → digite `MIGRAR` → **Fazer backup e migrar**.
5. **Conferência:**
   - o rodapé mostra **3.1.0**;
   - o menu tem **Automações**;
   - a Central mostra "Automações pausadas" e as 6 tarefas inativas;
   - **Publicações** continua "Pausado".
6. **(Quando quiser) Cron do executor**, a cada 15 minutos (cPanel → Cron Jobs):

   ```
   0,15,30,45 * * * * /usr/local/bin/php /home1/gust1922/gmaxcanais.com.br/gmax-auto/gmax-automacoes-cli.php executar-pendentes > /dev/null 2>&1
   ```

   Enquanto as automações estiverem pausadas, esse Cron só registra a hora da chamada. Ele **não envia mensagens** em nenhuma situação.
7. **Ativar as automações (decisão sua):**
   - na Central, cadastre os termos de pesquisa;
   - marque **ativa** nas tarefas desejadas e ajuste horários e dias → **Salvar**;
   - teste com **Executar agora**;
   - depois digite `RETOMAR` → **Retomar automações**.

## 3. Credenciais (fora da pasta pública)

| Fonte | Arquivo em `/home1/gust1922/gmax-secrets/` | Conteúdo |
|---|---|---|
| Mercado Livre | `mercadolivre-api.php` | `<?php return ['access_token' => '…'];` (token OAuth do seu aplicativo no DevCenter) |
| Shopee Affiliate | `shopee-api.php` | `<?php return ['app_id' => '…', 'secret' => '…'];` (quando a Shopee liberar) |

Permissão `0600`. Nenhum log ou tela mostra os tokens. Sem esses arquivos, a Central mostra "não configurado", e as tarefas pulam a fonte e abrem um alerta.

## 4. Rollback

| Situação | Ação |
|---|---|
| Parar tudo agora | Central → **Pausa geral** (ou desative o Cron do executor) |
| Desfazer uma decisão automática | Arquivados: **Ofertas** → filtro Arquivado → reabra. Fila: **Publicações** → Cancelar |
| Voltar o banco para antes da 3.1 | Sem automações rodando, Cron temporário `…/gmax-admin-cli.php reverter --confirmo` (uma vez). Isso remove **somente** as tabelas da migração 002, com backup antes; catálogo e links ficam intactos |
| Voltar os arquivos para a 3.0.x | Pausa geral → reverter (linha acima) → extraia `gmax-3.0.x-arquivos-AAAAMMDD.zip` por cima e apague `gmax-automacoes-cli.php`, `gmax-lib/Gmax/Automation/`, `gmax-lib/Gmax/RateLimiter.php`, `gmax-lib/Gmax/Connectors/{Discovery,Registry,NotAvailable}.php`, `gmax-lib/migrations/002_automacoes.php` e `gmax-lib/pages/automacoes.php` |
| Banco danificado | `ROLLBACK.md`, nível C, com o backup do passo 1 |

**Atenção:** com os arquivos de volta na 3.0.x, mas a migração 002 ainda aplicada, o painel pede "migração pendente" de forma estranha. Por isso, reverta a 002 **antes** de voltar os arquivos.
