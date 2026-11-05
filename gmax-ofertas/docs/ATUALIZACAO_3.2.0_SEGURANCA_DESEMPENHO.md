# GMAX Ofertas 3.2.0: segurança do acesso, limites operacionais e desempenho

**Base:** 3.1.9 instalada.
**Banco:** **nenhuma migração nova.** A instalação não altera o banco. As configurações novas (limites da seleção, cache da Visão Geral e continuação de tarefas) são gravadas sob demanda em `gmax3_settings`.
**Não alterado:**
- Envios continuam pausados e a aprovação automática continua desligada.
- Credenciais, OAuth do Mercado Livre e integração Shopee não foram alterados.
- Produtos, links, histórico e auditoria estão preservados.
- O Cron não mudou, e `app.php`, `cron.php` e o publicador antigo continuam iguais.
**Efeito visível:** depois de instalar, **todos precisam entrar de novo uma vez**. Nenhum usuário ou senha é apagado.

> Esta é só a 3.2.0. A 3.2.1 (retenção), a 3.2.2 (segmentos) e a 3.2.3 virão em pacotes separados, depois da validação desta.

## 1. O que foi corrigido

### 1.1 Acesso administrativo centralizado (`painel.php`)

| Antes (3.1.9) | Agora (3.2.0) |
|---|---|
| O `index.php` antigo tinha um segundo login, **sem limite de tentativas**, e também criava a senha inicial e importava para a tabela antiga `offers` | O `index.php` agora só redireciona (endereço fixo, sem parâmetros) para o `painel.php`. Antes de decidir, verifiquei a função real dele: nenhuma função pública dependia dele. A importação é feita pelo painel (Importar) |
| Uma sessão do login antigo abria o painel | Recusada: a sessão é encerrada e o painel pede novo login com o aviso "O acesso agora é centralizado neste painel" |
| Limite de 5 falhas por IP | 5 falhas por IP **e** 50 no total a cada 15 min. Contagem e registro ficam numa única transação, então pedidos em paralelo não escapam do limite. No IPv6 o limite vale para a rede /64 inteira |
| Sessão sem prazo | Expira após **2 h sem uso** e após **12 h** no máximo. O ID da sessão é regenerado no login e na expiração, e o token CSRF é renovado no login |
| `mercadolivre.php` aceitava qualquer sessão com `auth` | Aceita só a sessão do login do painel, com os mesmos prazos. A listagem continua idêntica |
| Senha inicial criada pelo `index.php` (via web) | Criada pela linha de comando, `gmax-admin-cli.php criar-senha`, a partir de `gmax-secrets/senha-inicial.txt`. Só funciona se não houver usuário, e o arquivo é apagado sempre |
| — | `gmax-admin-cli.php desbloquear-login` libera o login se um ataque atingir o limite global (ação registrada na auditoria) |
| — | Tentativas com mais de 1 dia são descartadas, então a tabela não cresce sem limite |

### 1.2 Execução manual com prazo seguro

O botão "Executar agora" trabalha com prazo de 100 s. Se o servidor tiver `max_execution_time` menor, o prazo passa a ser esse limite menos 15 s de folga. Fechar o navegador não interrompe a gravação do resultado (`ignore_user_abort`).

### 1.3 Tarefas longas: processamento incremental

Vale para a Revisão de preços e para a Análise semanal.
- **Pendências por falta de tempo:** a execução fica como **"parcial"**, nunca "concluída", e o detalhe informa quantos itens ficaram pendentes.
- **Continuação automática:** o próximo ciclo do Cron continua a execução, com chave própria (`… #cont1`, `#cont2`…). **Cada produto é consultado uma vez**: a continuação guarda os IDs já consultados e não depende da ordem, que muda conforme os preços são conferidos.
- **Execução interrompida pelo servidor:** vira "falhou" com alerta, e a retomada pula o que já tinha sido consultado (pelo registro detalhado da execução).
- **Limites:**
  - no máximo 6 continuações, dentro de 3 h; ao atingir o limite, há alerta e encerramento, sem laço infinito;
  - a pausa geral e a trava de execução impedem a continuação.
- **Itens além do "limite por execução" configurado:** não contam como pendência de tempo e ficam para o próximo horário, como antes.

### 1.4 Limites de seleção e de fila

A Seleção agenda no máximo:
- as **vagas livres** da janela, ou seja, os dias configurados (padrão 3) × o limite diário do Telegram privado (6) menos o que já está agendado;
- **2 por categoria** na janela.

Os dois valores são editáveis em Sistema → Configurações: de 1 a 14 dias e de 1 a 20 por categoria. Antes, a seleção podia encher a fila por semanas, toda com a mesma categoria. Nada é enviado: os itens só são **agendados**, e o envio continua pausado.

### 1.5 Ranking e Visão Geral mais rápidos (mesmo resultado)

- As regras usam um contexto pré-carregado: configurações, duplicidades da fila e mapas da tabela antiga são lidos uma vez, não uma vez por produto.
- O histórico é agregado no banco (`COUNT`/`MIN`).
- A lista completa é mantida em formato compacto, e o detalhamento (prontidão, pendências, destaques) só é calculado para a página exibida.
- A Visão Geral usa um cache de 5 min, invalidado na hora quando um produto, preço, histórico, categoria ou configuração do ranking muda.

### 1.6 Crescimento de registros limitado (sem exclusões)

- **Revisão:** cada execução grava no máximo 100 linhas de "não processado" no registro detalhado, e o total continua no resumo. O limite de 300 linhas "fora da revisão" continua valendo.
- **Tentativas de login:** só as das últimas 24 h ficam guardadas.
- **Demais registros:** nenhum foi apagado. A retenção com simulação, exportação e backup verificado fica para a 3.2.1, como combinado.

### 1.7 Correção menor

Foi removido um aviso do PHP (`price_verified_by` ausente) no cálculo da origem do preço original. Ele já existia na 3.1.9.

## 2. Benchmarks reais (antes × depois)

Para reproduzir: `php -d memory_limit=512M tools/benchmark_ranking.php <rótulo> 1000,10000`. A ferramenta usa um banco isolado de teste, nunca o de produção, com 10 registros de histórico por produto e até 300 itens na fila. Os arquivos estão em `benchmarks/benchmark-ranking-antes.json` (código da 3.1.9) e `benchmarks/benchmark-ranking-depois.json` (3.2.0). Melhor de 3 execuções, PHP 8.3.6 e SQLite 3.45.1, **no ambiente de desenvolvimento (4 núcleos), não na HostGator**. Os números servem para comparar, não como tempo absoluto do servidor.

| Base | Operação | Antes | Depois | Resultado idêntico? |
|---|---|---|---|---|
| 1.000 | Ranking, modo potencial (página 1) | 329 ms · 8,3 MB | 53 ms · 1,6 MB | sim |
| 1.000 | Ranking, modo prontas | 306 ms · 4,2 MB | 46 ms · 0,6 MB | sim |
| 1.000 | Ranking, filtros combinados (página 2) | 106 ms · 4,5 MB | 26 ms · 1,1 MB | sim |
| 1.000 | Visão Geral (top 5), sem cache | 31 ms · 6,6 MB | 28 ms · 1,0 MB | sim |
| 1.000 | Visão Geral (top 5), com cache | — | 2,8 ms | sim |
| 10.000 | Ranking, modo potencial (página 1) | **4.556 ms · 79,2 MB** | **404 ms · 8,5 MB** | sim |
| 10.000 | Ranking, modo prontas | **4.171 ms · 42,1 MB** | **437 ms · 4,5 MB** | sim |
| 10.000 | Ranking, filtros combinados (página 2) | 1.380 ms · 42,3 MB | 228 ms · 5,9 MB | sim |
| 10.000 | Visão Geral (top 5), sem cache | 366 ms · 65,5 MB | 352 ms · 7,4 MB | sim |
| 10.000 | Visão Geral (top 5), com cache | — | 9,8 ms | sim |
| 10.000 | Busca de ofertas | 15,3 ms | 15,3 ms | — |

"Resultado idêntico" significa que a impressão digital SHA-256 é igual. Ela é calculada sobre total, prontas e, em cada linha, ID, pontuação, confiança, prontidão, pendências e destaques. Ou seja, a otimização **não mudou o ranking**. Numa medição anterior da 3.2.0 os tempos foram menores, por exemplo 347 ms no modo potencial com 10.000 produtos. A variação entre execuções é do ambiente; a tabela mostra a medição final.

## 3. Testes executados

A lista completa, gerada automaticamente, está em `TESTES.md`.

| Suíte | Resultado |
|---|---|
| `tests/v320_test.php` (novo, 15 testes) | Login: limite global, concorrência real com 12 processos, IPv6 /64, descarte de tentativas antigas e desbloqueio pela CLI. Senha inicial: nunca sobrescreve; 14 a 72 caracteres; o arquivo é apagado mesmo com erro; link simbólico recusado; BOM removido. Regras com contexto iguais às originais, produto a produto. Cache da Visão Geral. Tarefa longa: parcial, continuação e cada produto uma vez. Interrupção e retomada sem repetir. Concorrência: trava e pausa. Limite de continuações. Prazo manual. Seleção: vagas e categoria. Limites configuráveis pelo painel. Teto de linhas no registro |
| `tests/http_test.php` (44) | Servidor PHP real: `index.php` só redireciona; login centralizado; sessão antiga recusada; expiração de 2 h e 12 h; regeneração do ID; `mercadolivre.php` com as novas regras |
| Demais 12 suítes | Regressão completa, incluindo a interface no Chromium com 9 perfis emulados |

## 4. Revisão independente de segurança

Um revisor independente (agente separado, só leitura) avaliou as mudanças e **não encontrou falha crítica nem forma de contornar o login, publicar ou enviar mensagens**.

| Achado | Gravidade | Situação |
|---|---|---|
| `mercadolivre.php` aceitava sessões antigas e sem prazo | Média | **Corrigido** (1.1) |
| Um ataque pode bloquear o login de todos por 15 min (limite global); troca de IPv6 dentro da mesma rede | Média | **Mitigado:** comando `desbloquear-login` e agrupamento /64. O bloqueio temporário é o preço de impedir a adivinhação distribuída (ver riscos) |
| Antes da migração 001 o limite de tentativas é só por sessão | Baixa/média | Risco residual: a produção já tem a 001 desde a 3.0 |
| `criar-senha`: link simbólico, BOM, falha ao apagar silenciosa, senha acima de 72 bytes, permissões | Baixa | **Corrigido:** recusa link, remove BOM, avisa se não conseguir apagar, aceita de 14 a 72 caracteres e avisa sobre permissão diferente de 600 |
| Tabela de tentativas sem limpeza | Baixa | **Corrigido:** descarte após 24 h |
| Cookie `Secure` depende de `HTTPS` no servidor | Baixa | Risco residual: a HostGator entrega HTTPS diretamente ao PHP |
| Cache da Visão Geral pode ficar até 5 min desatualizado | Baixa | Aceito: só exibição. As automações não usam o cache |
| O original do `index.php` não pode ir para a pasta pública | Info | O original vai em `rollback/`, **fora** do ZIP de arquivos |

O revisor confirmou como corretos:
- sessão estrita, só por cookie, com regeneração;
- CSRF em todo POST;
- contagem atômica de tentativas, sem confiar em `X-Forwarded-For`;
- redirecionamento fixo, sem open redirect;
- JSON das configurações sem `unserialize`;
- continuação limitada;
- a seleção só agenda, passando pelas travas de envio já existentes.

## 5. Instalação

1. **Backup:** em Sistema, clique em **Criar backup agora** e compacte a pasta `gmax-auto/` inteira pelo Gerenciador de Arquivos (ela inclui o `index.php` e o `mercadolivre.php` atuais).
2. **Envio:** extraia **somente** `GMAX_Ofertas_3.2.0_incremental_arquivos.zip` em `gmax-auto/`. A lista está no `MANIFESTO.txt`. **Não** envie a pasta `rollback/` ao servidor.
3. **Recarregue** com Ctrl+F5 e entre de novo com a sua senha. O rodapé deve mostrar **3.2.0**.
4. **Conferências:**
   - abrir `…/gmax-auto/index.php` deve levar ao login do painel;
   - `mercadolivre.php` deve abrir normalmente depois do login;
   - Sistema → Verificação deve mostrar `index.php` "substituído pelo redirecionamento…" e `mercadolivre.php` "atualizado para a sessão do login centralizado…".
5. **Opcional:** em Sistema → Configurações, ajuste os dois limites da seleção. Os padrões são 3 dias e 2 por categoria.

Nenhuma migração é necessária, o Cron não muda e nada é ativado.

## 6. Rollback

1. Restaure o backup de arquivos do passo 1, **ou** extraia de novo o pacote da 3.1.9 e copie:
   - `rollback/index.php.original` → `gmax-auto/index.php`;
   - `rollback/mercadolivre.php.original` → `gmax-auto/mercadolivre.php`.
2. Não é preciso mexer no banco. As chaves novas em `gmax3_settings` (`cache_ranking_topo`, `automacoes_continuacao`, `selecao_horizonte_dias` e `selecao_max_por_categoria`) são ignoradas pela 3.1.9.
3. Depois do rollback, entre de novo pelo painel.

## 7. Riscos residuais

1. **Bloqueio global do login:** quem disparar 50 senhas erradas em 15 min, de redes diferentes, bloqueia o login de todos (inclusive o seu) por 15 min. Para liberar na hora: `php gmax-admin-cli.php desbloquear-login`.
2. **Limite antes da migração 001:** sem a tabela de tentativas, o limite é só por sessão. Não se aplica à produção atual, que já tem a 001.
3. **Cookie `Secure`:** se um dia houver proxy ou CDN terminando o HTTPS antes do PHP, o cookie pode sair sem `Secure`. Hoje a HostGator entrega HTTPS diretamente.
4. **Visão Geral:** pode exibir uma lista com até 5 min de atraso para mudanças que não passam pelo catálogo, histórico, categorias ou configurações do ranking.
5. **Continuação:**
   - roda só com a tarefa ativa e as automações não pausadas; se estiverem pausadas, o restante fica para o próximo horário agendado;
   - uma retomada após interrupção do servidor, **sem** a migração 003, não sabe o que já foi consultado: recorre à ordem por preço mais antigo e pode repetir alguns produtos.
6. **Prazo:** o de 100 s pressupõe que o Cron da HostGator permita isso. A execução manual se ajusta ao `max_execution_time`, mas o tempo real do servidor não foi medido aqui.
7. **Benchmarks:** medidos em desenvolvimento, não na HostGator.
8. **Importação antiga:** a importação para a tabela antiga `offers` pelo `index.php` deixou de existir. O painel (Importar) cobre a importação.
9. **SQLite:** continua sem WAL. A pergunta técnica para a HostGator está em `PERGUNTA_HOSTGATOR_SQLITE_WAL.md`.

## 8. Critérios de aceite (para validar no servidor)

| # | Critério | Como verificar |
|---|---|---|
| 1 | Rodapé 3.2.0 e painel abrindo normalmente após novo login | Ctrl+F5 e login |
| 2 | `index.php` leva ao login do painel, sem formulário antigo | Abrir `…/gmax-auto/index.php` |
| 3 | Senha errada 5 vezes gera "Muitas tentativas" | Teste com senha errada; libere depois com `desbloquear-login` ou aguarde 15 min |
| 4 | Sessão expira após 2 h sem uso | Deixar o painel aberto mais de 2 h e clicar em qualquer página |
| 5 | `mercadolivre.php` abre após o login e lista as mesmas ofertas de antes | Abrir a página |
| 6 | Verificação do Sistema sem falhas, com `index.php` e `mercadolivre.php` reconhecidos | Sistema → Verificação |
| 7 | Ranking e Visão Geral com os mesmos produtos e pontuações de antes, mais rápidos | Comparar com uma captura de tela feita antes de instalar |
| 8 | Execução manual da Revisão de preços termina com "concluído" ou "parcial", nunca "executando" parada | Automações → Executar agora |
| 9 | Envios pausados, aprovação automática desligada, Cron inalterado | Sistema e cPanel → Cron |
| 10 | OAuth Mercado Livre conectado e Shopee respondendo | Integrações → Testar conexão |
