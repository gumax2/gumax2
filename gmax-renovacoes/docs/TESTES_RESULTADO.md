# Resultado dos testes executados

Gerado automaticamente por `php tests/run.php` em 30/10/2026 09:34 (America/Sao_Paulo).

Ambiente: PHP 8.3.6, 10.11.14-MariaDB-0ubuntu0.24.04.1. Dados fictícios. Nenhuma chamada de rede.

**89 de 89 testes passaram.**

| # | Teste | Resultado | Tempo |
|---|---|---|---|
| 1 | cliente com dois pacotes e vencimentos diferentes gera lembretes separados | ✅ passou | 0.13s |
| 2 | vários pacotes na mesma data viram UMA mensagem com pacotes e valores | ✅ passou | 0.08s |
| 3 | preço total não é multiplicado pelos pontos | ✅ passou | 0.09s |
| 4 | ciclos trimestral e anual informam o valor do ciclo | ✅ passou | 0.11s |
| 5 | mensagens não contêm senha nem URL da lista | ✅ passou | 0.10s |
| 6 | renovação entre a geração e o envio cancela só aquele pacote | ✅ passou | 0.09s |
| 7 | renovação durante espera de nova tentativa cancela a mensagem | ✅ passou | 0.12s |
| 8 | renovação de um pacote não cancela avisos de outro pacote do cliente | ✅ passou | 0.11s |
| 9 | aviso de 1 dia após não sai se o pacote foi renovado | ✅ passou | 0.12s |
| 10 | cliente excluído, cliente pausado e pacote pausado não recebem | ✅ passou | 0.10s |
| 11 | cliente pausado no módulo e cliente excluído entre geração e envio | ✅ passou | 0.10s |
| 12 | sem consentimento não envia; autorização no mesmo dia libera; revogação bloqueia | ✅ passou | 0.10s |
| 13 | revogação entre a geração e o envio impede o envio | ✅ passou | 0.09s |
| 14 | telefone inválido ou alterado após autorização bloqueia | ✅ passou | 0.09s |
| 15 | normalização de telefones brasileiros | ✅ passou | 0.06s |
| 16 | ano bissexto: 29/02/2028 nas três etapas | ✅ passou | 0.12s |
| 17 | virada de mês e de ano | ✅ passou | 0.12s |
| 18 | fuso America/Sao_Paulo: antes das 09h nada é gerado | ✅ passou | 0.09s |
| 19 | fim da janela de envio: depois das 20h não envia | ✅ passou | 0.06s |
| 20 | ativar o módulo não dispara lembretes antigos | ✅ passou | 0.09s |
| 21 | ativação com data futura não gera nada antes dela | ✅ passou | 0.07s |
| 22 | lembretes pendentes de ontem expiram e não são enviados hoje | ✅ passou | 0.08s |
| 23 | agendamento repetido não reenvia | ✅ passou | 0.11s |
| 24 | trava impede execução simultânea no mesmo processo de teste | ✅ passou | 0.06s |
| 25 | três trabalhadores simultâneos (com trava do agendador) | ✅ passou | 1.10s |
| 26 | três trabalhadores simultâneos SEM a trava (reivindicação atômica) | ✅ passou | 1.11s |
| 27 | falha temporária: novas tentativas limitadas e depois "falhou" | ✅ passou | 0.12s |
| 28 | falha permanente não repete; erro de credencial ativa pausa global | ✅ passou | 0.14s |
| 29 | timeout gera "incerto" e não reenvia automaticamente | ✅ passou | 0.12s |
| 30 | envio interrompido (processo caiu) vira "incerto" | ✅ passou | 0.10s |
| 31 | reenvio manual após incerto só no mesmo dia | ✅ passou | 0.12s |
| 32 | callbacks repetidos e fora de ordem | ✅ passou | 0.10s |
| 33 | "entregue" só com callback; falha via callback antes da entrega | ✅ passou | 0.08s |
| 34 | callback antes de gravar o ID (órfão) é aplicado depois; incerto resolvido por referência | ✅ passou | 0.10s |
| 35 | webhook: assinatura inválida rejeitada e verificação GET | ✅ passou | 0.08s |
| 36 | descadastro por resposta SAIR revoga a autorização | ✅ passou | 0.11s |
| 37 | classificação de respostas da Meta | ✅ passou | 0.09s |
| 38 | payload do modelo da Meta (sem rede) | ✅ passou | 0.09s |
| 39 | modo simulação nunca usa provedor real | ✅ passou | 0.09s |
| 40 | modo real com "somente números de teste" bloqueia clientes | ✅ passou | 0.09s |
| 41 | mensagem de teste exige número da lista interna | ✅ passou | 0.07s |
| 42 | validação dos textos editáveis | ✅ passou | 0.07s |
| 43 | migração reaplicável sem perder configurações | ✅ passou | 0.08s |
| 44 | histórico financeiro e cadastros preservados (somente leitura) | ✅ passou | 0.16s |
| 45 | código do módulo não escreve em tabelas do painel | ✅ passou | 0.08s |
| 46 | descadastro: wa_id sem o 9º dígito, frases curtas, outro número ignorado, respostas registradas | ✅ passou | 0.08s |
| 47 | registrar envio manual antes das 09h impede o envio automático | ✅ passou | 0.10s |
| 48 | decisão manual recusada se o estado mudou no meio | ✅ passou | 0.12s |
| 49 | instruções de pagamento e rodapé não aceitam links nem senhas | ✅ passou | 0.10s |
| 50 | API rejeita formatos inválidos e cliente inexistente | ✅ passou | 0.07s |
| 51 | prévias usam a data coerente com cada etapa | ✅ passou | 0.06s |
| 52 | instalação em banco com dados existentes: estrutura, IDs, conteúdo e totais idênticos; reaplicar não muda nada | ✅ passou | 0.23s |
| 53 | cliente com 4 pacotes: renovar 1 pelo fluxo do painel altera só os avisos desse pacote | ✅ passou | 0.09s |
| 54 | recebimento SEM nova data não conta como renovação; dados legados do cliente são ignorados | ✅ passou | 0.08s |
| 55 | preço e telefone alterados entre geração e envio: revalidação usa os dados atuais | ✅ passou | 0.10s |
| 56 | cliente excluído (client_exclusions) nunca é selecionado nem listado; restauração é do painel | ✅ passou | 0.08s |
| 57 | patch do menu: insere, recusa repetir, reverte byte a byte e recusa index desconhecido | ✅ passou | 0.08s |
| 58 | controle negativo: a verificação de integridade detecta 1 centavo, 1 exclusão e 1 coluna nova | ✅ passou | 0.14s |
| 59 | migrações e SQL de instalação: só CREATE TABLE IF NOT EXISTS gr_* e INSERT IGNORE em gr_* | ✅ passou | 0.12s |
| 60 | R2-1: banco com a versão antiga (IDs numéricos) é recusado sem alterar nada | ✅ passou | 0.11s |
| 61 | R2-2: envio manual registrado em simulação impede o envio automático no modo real | ✅ passou | 0.11s |
| 62 | R2-3: vencimento inválido no painel não derruba a aba e é contado | ✅ passou | 0.08s |
| 63 | R2-4: simulação nunca aparece como "Aceito pelo provedor" | ✅ passou | 0.09s |
| 64 | CLI: integridade antes/depois + migrar + comparar, e menu recusa modo inválido | ✅ passou | 0.45s |
| 65 | web: API do módulo sem sessão do painel → 401 JSON; cookie forjado também | ✅ passou | 0.18s |
| 66 | web: renovacoes.php sem sessão redireciona para o login do painel (./) sem dados | ✅ passou | 0.11s |
| 67 | web: login REAL no api.php dá acesso a renovacoes.php e à API; CSRF do painel vale nas mutações do módulo | ✅ passou | 0.22s |
| 68 | web: logout do painel (api.php action=logout) → API do módulo 401 e página redireciona | ✅ passou | 0.18s |
| 69 | web: sessão expirada ($_SESSION[last] > 3600 s) → 401 no módulo e no api.php; cookie regenerado continua forte | ✅ passou | 0.38s |
| 70 | web: Set-Cookie do módulo não enfraquece o cookie do painel (path=/, SameSite=Strict, HttpOnly) | ✅ passou | 0.07s |
| 71 | web: exigir_https=true (padrão) bloqueia HTTP no módulo, mesmo logado | ✅ passou | 0.15s |
| 72 | web: página do módulo com cabeçalhos de segurança, sem inline, assets 200 | ✅ passou | 0.17s |
| 73 | web: mutação via GET → 405 e nada muda | ✅ passou | 0.13s |
| 74 | web: POST sem CSRF / CSRF errado / de outra sessão → 403; correto → 200; corpo não-JSON → 400 | ✅ passou | 0.21s |
| 75 | web: Origin/Referer de outro site → 403 mesmo com token válido | ✅ passou | 0.14s |
| 76 | web: leituras do módulo não expõem segredos (config do módulo, config do painel, códigos/senhas dos pacotes) | ✅ passou | 0.34s |
| 77 | web: salvar_config rejeita URL/senha nos textos e no rodapé e não grava | ✅ passou | 0.19s |
| 78 | web: previa devolve o texto renderizado (GET e POST com CSRF) | ✅ passou | 0.16s |
| 79 | web: painel do módulo agrupa pacotes do mesmo cliente/data (total) e separa outra data (client_plans.due_date) | ✅ passou | 0.16s |
| 80 | web: executar_agora cria mensagens SIMULADAS no histórico; sem autorização não recebe; repetir não duplica | ✅ passou | 0.28s |
| 81 | web: regressão do painel — api.php data idêntico antes/depois de instalar e usar o módulo; index.html com patch ok | ✅ passou | 0.42s |
| 82 | web: renovação pelo api.php REAL entre geração e despacho cancela só o pacote renovado; os demais seguem | ✅ passou | 0.23s |
| 83 | web: cliente excluído pelo api.php (delete_client) some do módulo, não é selecionado nem restaurado | ✅ passou | 0.23s |
| 84 | web: módulo recusa registrar autorização para cliente excluído no painel | ✅ passou | 0.20s |
| 85 | web: webhook GET verify — token certo devolve o challenge, errado 403 | ✅ passou | 0.09s |
| 86 | web: webhook POST com HMAC válido atualiza status; inválido 401; repetido é idempotente | ✅ passou | 0.11s |
| 87 | web: webhook "SAIR" assinado revoga a autorização; sem assinatura não revoga | ✅ passou | 0.07s |
| 88 | web (Chromium): login pelo formulário do painel, link Renovações no menu, aba sem erros de console/CSP, sem XSS, sem rolagem horizontal em 390px | ✅ passou | 12.12s |
| 89 | web: servidor não registrou avisos/erros PHP durante os testes | ✅ passou | 0.09s |
