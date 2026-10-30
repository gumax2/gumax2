# Resultado dos testes executados

Gerado automaticamente por `php tests/run.php` em 30/10/2026 10:01 (America/Sao_Paulo).

Ambiente: PHP 8.3.6, 10.11.14-MariaDB-0ubuntu0.24.04.1. Dados fictícios. Nenhuma chamada de rede.

**97 de 97 testes executados passaram.** Nenhum teste pulado.

| # | Teste | Resultado | Tempo |
|---|---|---|---|
| 1 | apache: .htaccess Files 2.4 (Require) — bloqueia antes, patch libera só os 3 arquivos, sessão e assinatura mantidas, remover restaura | ✅ passou | 0.61s |
| 2 | apache: .htaccess Files 2.2 (Order/Deny) — bloqueia antes, patch libera só os 3 arquivos, sessão e assinatura mantidas, remover restaura | ✅ passou | 0.66s |
| 3 | apache: .htaccess Rewrite na pasta do painel — bloqueia antes, patch libera só os 3 arquivos, sessão e assinatura mantidas, remover restaura | ✅ passou | 0.63s |
| 4 | apache: .htaccess Rewrite na raiz do domínio — bloqueia antes, patch libera só os 3 arquivos, sessão e assinatura mantidas, remover restaura | ✅ passou | 0.67s |
| 5 | apache: sem regra de bloqueio — verificar não propõe mudança e o módulo responde | ✅ passou | 0.39s |
| 6 | apache: formato desconhecido (bloqueia tudo, inclusive api.php) — recusa sem alterar | ✅ passou | 0.37s |
| 7 | cliente com dois pacotes e vencimentos diferentes gera lembretes separados | ✅ passou | 0.11s |
| 8 | vários pacotes na mesma data viram UMA mensagem com pacotes e valores | ✅ passou | 0.10s |
| 9 | preço total não é multiplicado pelos pontos | ✅ passou | 0.11s |
| 10 | ciclos trimestral e anual informam o valor do ciclo | ✅ passou | 0.11s |
| 11 | mensagens não contêm senha nem URL da lista | ✅ passou | 0.10s |
| 12 | renovação entre a geração e o envio cancela só aquele pacote | ✅ passou | 0.11s |
| 13 | renovação durante espera de nova tentativa cancela a mensagem | ✅ passou | 0.11s |
| 14 | renovação de um pacote não cancela avisos de outro pacote do cliente | ✅ passou | 0.12s |
| 15 | aviso de 1 dia após não sai se o pacote foi renovado | ✅ passou | 0.11s |
| 16 | cliente excluído, cliente pausado e pacote pausado não recebem | ✅ passou | 0.12s |
| 17 | cliente pausado no módulo e cliente excluído entre geração e envio | ✅ passou | 0.12s |
| 18 | sem consentimento não envia; autorização no mesmo dia libera; revogação bloqueia | ✅ passou | 0.12s |
| 19 | revogação entre a geração e o envio impede o envio | ✅ passou | 0.11s |
| 20 | telefone inválido ou alterado após autorização bloqueia | ✅ passou | 0.10s |
| 21 | normalização de telefones brasileiros | ✅ passou | 0.09s |
| 22 | ano bissexto: 29/02/2028 nas três etapas | ✅ passou | 0.14s |
| 23 | virada de mês e de ano | ✅ passou | 0.12s |
| 24 | fuso America/Sao_Paulo: antes das 09h nada é gerado | ✅ passou | 0.10s |
| 25 | fim da janela de envio: depois das 20h não envia | ✅ passou | 0.09s |
| 26 | ativar o módulo não dispara lembretes antigos | ✅ passou | 0.09s |
| 27 | ativação com data futura não gera nada antes dela | ✅ passou | 0.07s |
| 28 | lembretes pendentes de ontem expiram e não são enviados hoje | ✅ passou | 0.09s |
| 29 | agendamento repetido não reenvia | ✅ passou | 0.10s |
| 30 | trava impede execução simultânea no mesmo processo de teste | ✅ passou | 0.09s |
| 31 | três trabalhadores simultâneos (com trava do agendador) | ✅ passou | 1.09s |
| 32 | três trabalhadores simultâneos SEM a trava (reivindicação atômica) | ✅ passou | 1.14s |
| 33 | falha temporária: novas tentativas limitadas e depois "falhou" | ✅ passou | 0.13s |
| 34 | falha permanente não repete; erro de credencial ativa pausa global | ✅ passou | 0.16s |
| 35 | timeout gera "incerto" e não reenvia automaticamente | ✅ passou | 0.10s |
| 36 | envio interrompido (processo caiu) vira "incerto" | ✅ passou | 0.13s |
| 37 | reenvio manual após incerto só no mesmo dia | ✅ passou | 0.14s |
| 38 | callbacks repetidos e fora de ordem | ✅ passou | 0.10s |
| 39 | "entregue" só com callback; falha via callback antes da entrega | ✅ passou | 0.07s |
| 40 | callback antes de gravar o ID (órfão) é aplicado depois; incerto resolvido por referência | ✅ passou | 0.09s |
| 41 | webhook: assinatura inválida rejeitada e verificação GET | ✅ passou | 0.09s |
| 42 | descadastro por resposta SAIR revoga a autorização | ✅ passou | 0.08s |
| 43 | classificação de respostas da Meta | ✅ passou | 0.07s |
| 44 | payload do modelo da Meta (sem rede) | ✅ passou | 0.06s |
| 45 | modo simulação nunca usa provedor real | ✅ passou | 0.08s |
| 46 | modo real com "somente números de teste" bloqueia clientes | ✅ passou | 0.08s |
| 47 | mensagem de teste exige número da lista interna | ✅ passou | 0.07s |
| 48 | validação dos textos editáveis | ✅ passou | 0.08s |
| 49 | migração reaplicável sem perder configurações | ✅ passou | 0.09s |
| 50 | histórico financeiro e cadastros preservados (somente leitura) | ✅ passou | 0.15s |
| 51 | código do módulo não escreve em tabelas do painel | ✅ passou | 0.08s |
| 52 | descadastro: wa_id sem o 9º dígito, frases curtas, outro número ignorado, respostas registradas | ✅ passou | 0.08s |
| 53 | registrar envio manual antes das 09h impede o envio automático | ✅ passou | 0.14s |
| 54 | decisão manual recusada se o estado mudou no meio | ✅ passou | 0.11s |
| 55 | instruções de pagamento e rodapé não aceitam links nem senhas | ✅ passou | 0.07s |
| 56 | API rejeita formatos inválidos e cliente inexistente | ✅ passou | 0.06s |
| 57 | prévias usam a data coerente com cada etapa | ✅ passou | 0.08s |
| 58 | instalação em banco com dados existentes: estrutura, IDs, conteúdo e totais idênticos; reaplicar não muda nada | ✅ passou | 0.29s |
| 59 | cliente com 4 pacotes: renovar 1 pelo fluxo do painel altera só os avisos desse pacote | ✅ passou | 0.11s |
| 60 | recebimento SEM nova data não conta como renovação; dados legados do cliente são ignorados | ✅ passou | 0.10s |
| 61 | preço e telefone alterados entre geração e envio: revalidação usa os dados atuais | ✅ passou | 0.12s |
| 62 | cliente excluído (client_exclusions) nunca é selecionado nem listado; restauração é do painel | ✅ passou | 0.08s |
| 63 | patch do menu: insere, recusa repetir, reverte byte a byte e recusa index desconhecido | ✅ passou | 0.11s |
| 64 | controle negativo: a verificação de integridade detecta 1 centavo, 1 exclusão e 1 coluna nova | ✅ passou | 0.15s |
| 65 | migrações e SQL de instalação: só CREATE TABLE IF NOT EXISTS gr_* e INSERT IGNORE em gr_* | ✅ passou | 0.08s |
| 66 | R2-1: banco com a versão antiga (IDs numéricos) é recusado sem alterar nada | ✅ passou | 0.09s |
| 67 | R2-2: envio manual registrado em simulação impede o envio automático no modo real | ✅ passou | 0.11s |
| 68 | R2-3: vencimento inválido no painel não derruba a aba e é contado | ✅ passou | 0.08s |
| 69 | R2-4: simulação nunca aparece como "Aceito pelo provedor" | ✅ passou | 0.09s |
| 70 | CLI: integridade antes/depois + migrar + comparar, e menu recusa modo inválido | ✅ passou | 0.43s |
| 71 | documentação e exemplos usam o caminho real do painel (gmaxcanais.com.br/painel), nunca public_html | ✅ passou | 0.08s |
| 72 | .htaccess: 4 formatos liberam só os 3 arquivos, reversão exata; sem bloqueio não muda; desconhecido recusa | ✅ passou | 0.09s |
| 73 | web: API do módulo sem sessão do painel → 401 JSON; cookie forjado também | ✅ passou | 0.15s |
| 74 | web: renovacoes.php sem sessão redireciona para o login do painel (./) sem dados | ✅ passou | 0.08s |
| 75 | web: login REAL no api.php dá acesso a renovacoes.php e à API; CSRF do painel vale nas mutações do módulo | ✅ passou | 0.17s |
| 76 | web: logout do painel (api.php action=logout) → API do módulo 401 e página redireciona | ✅ passou | 0.13s |
| 77 | web: sessão expirada ($_SESSION[last] > 3600 s) → 401 no módulo e no api.php; cookie regenerado continua forte | ✅ passou | 0.36s |
| 78 | web: Set-Cookie do módulo não enfraquece o cookie do painel (path=/, SameSite=Strict, HttpOnly) | ✅ passou | 0.08s |
| 79 | web: exigir_https=true (padrão) bloqueia HTTP no módulo, mesmo logado | ✅ passou | 0.18s |
| 80 | web: página do módulo com cabeçalhos de segurança, sem inline, assets 200 | ✅ passou | 0.18s |
| 81 | web: mutação via GET → 405 e nada muda | ✅ passou | 0.16s |
| 82 | web: POST sem CSRF / CSRF errado / de outra sessão → 403; correto → 200; corpo não-JSON → 400 | ✅ passou | 0.22s |
| 83 | web: Origin/Referer de outro site → 403 mesmo com token válido | ✅ passou | 0.18s |
| 84 | web: leituras do módulo não expõem segredos (config do módulo, config do painel, códigos/senhas dos pacotes) | ✅ passou | 0.31s |
| 85 | web: salvar_config rejeita URL/senha nos textos e no rodapé e não grava | ✅ passou | 0.17s |
| 86 | web: previa devolve o texto renderizado (GET e POST com CSRF) | ✅ passou | 0.19s |
| 87 | web: painel do módulo agrupa pacotes do mesmo cliente/data (total) e separa outra data (client_plans.due_date) | ✅ passou | 0.17s |
| 88 | web: executar_agora cria mensagens SIMULADAS no histórico; sem autorização não recebe; repetir não duplica | ✅ passou | 0.23s |
| 89 | web: regressão do painel — api.php data idêntico antes/depois de instalar e usar o módulo; index.html com patch ok | ✅ passou | 0.34s |
| 90 | web: renovação pelo api.php REAL entre geração e despacho cancela só o pacote renovado; os demais seguem | ✅ passou | 0.22s |
| 91 | web: cliente excluído pelo api.php (delete_client) some do módulo, não é selecionado nem restaurado | ✅ passou | 0.27s |
| 92 | web: módulo recusa registrar autorização para cliente excluído no painel | ✅ passou | 0.15s |
| 93 | web: webhook GET verify — token certo devolve o challenge, errado 403 | ✅ passou | 0.10s |
| 94 | web: webhook POST com HMAC válido atualiza status; inválido 401; repetido é idempotente | ✅ passou | 0.11s |
| 95 | web: webhook "SAIR" assinado revoga a autorização; sem assinatura não revoga | ✅ passou | 0.09s |
| 96 | web (Chromium): login pelo formulário do painel, link Renovações no menu, aba sem erros de console/CSP, sem XSS, sem rolagem horizontal em 390px | ✅ passou | 12.66s |
| 97 | web: servidor não registrou avisos/erros PHP durante os testes | ✅ passou | 0.07s |
