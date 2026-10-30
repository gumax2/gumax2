# Resultado dos testes executados

Gerado automaticamente por `php tests/run.php` em 30/10/2026 09:12 (America/Sao_Paulo).

Ambiente: PHP 8.3.6, 10.11.14-MariaDB-0ubuntu0.24.04.1. Dados fictícios. Nenhuma chamada de rede.

**69 de 69 testes passaram.**

| # | Teste | Resultado | Tempo |
|---|---|---|---|
| 1 | cliente com dois pacotes e vencimentos diferentes gera lembretes separados | ✅ passou | 0.11s |
| 2 | vários pacotes na mesma data viram UMA mensagem com pacotes e valores | ✅ passou | 0.07s |
| 3 | preço total não é multiplicado pelos pontos | ✅ passou | 0.09s |
| 4 | ciclos trimestral e anual informam o valor do ciclo | ✅ passou | 0.13s |
| 5 | mensagens não contêm senha nem URL da lista | ✅ passou | 0.07s |
| 6 | renovação entre a geração e o envio cancela só aquele pacote | ✅ passou | 0.08s |
| 7 | renovação durante espera de nova tentativa cancela a mensagem | ✅ passou | 0.08s |
| 8 | renovação de um pacote não cancela avisos de outro pacote do cliente | ✅ passou | 0.07s |
| 9 | aviso de 1 dia após não sai se o pacote foi renovado | ✅ passou | 0.06s |
| 10 | cliente excluído, cliente pausado e pacote pausado não recebem | ✅ passou | 0.07s |
| 11 | cliente pausado no módulo e cliente excluído entre geração e envio | ✅ passou | 0.11s |
| 12 | sem consentimento não envia; autorização no mesmo dia libera; revogação bloqueia | ✅ passou | 0.07s |
| 13 | revogação entre a geração e o envio impede o envio | ✅ passou | 0.08s |
| 14 | telefone inválido ou alterado após autorização bloqueia | ✅ passou | 0.08s |
| 15 | normalização de telefones brasileiros | ✅ passou | 0.05s |
| 16 | ano bissexto: 29/02/2028 nas três etapas | ✅ passou | 0.10s |
| 17 | virada de mês e de ano | ✅ passou | 0.10s |
| 18 | fuso America/Sao_Paulo: antes das 09h nada é gerado | ✅ passou | 0.07s |
| 19 | fim da janela de envio: depois das 20h não envia | ✅ passou | 0.07s |
| 20 | ativar o módulo não dispara lembretes antigos | ✅ passou | 0.08s |
| 21 | ativação com data futura não gera nada antes dela | ✅ passou | 0.05s |
| 22 | lembretes pendentes de ontem expiram e não são enviados hoje | ✅ passou | 0.06s |
| 23 | agendamento repetido não reenvia | ✅ passou | 0.07s |
| 24 | trava impede execução simultânea no mesmo processo de teste | ✅ passou | 0.06s |
| 25 | três trabalhadores simultâneos (com trava do agendador) | ✅ passou | 1.08s |
| 26 | três trabalhadores simultâneos SEM a trava (reivindicação atômica) | ✅ passou | 0.44s |
| 27 | falha temporária: novas tentativas limitadas e depois "falhou" | ✅ passou | 0.13s |
| 28 | falha permanente não repete; erro de credencial ativa pausa global | ✅ passou | 0.09s |
| 29 | timeout gera "incerto" e não reenvia automaticamente | ✅ passou | 0.07s |
| 30 | envio interrompido (processo caiu) vira "incerto" | ✅ passou | 0.06s |
| 31 | reenvio manual após incerto só no mesmo dia | ✅ passou | 0.07s |
| 32 | callbacks repetidos e fora de ordem | ✅ passou | 0.05s |
| 33 | "entregue" só com callback; falha via callback antes da entrega | ✅ passou | 0.06s |
| 34 | callback antes de gravar o ID (órfão) é aplicado depois; incerto resolvido por referência | ✅ passou | 0.10s |
| 35 | webhook: assinatura inválida rejeitada e verificação GET | ✅ passou | 0.04s |
| 36 | descadastro por resposta SAIR revoga a autorização | ✅ passou | 0.05s |
| 37 | classificação de respostas da Meta | ✅ passou | 0.05s |
| 38 | payload do modelo da Meta (sem rede) | ✅ passou | 0.05s |
| 39 | modo simulação nunca usa provedor real | ✅ passou | 0.05s |
| 40 | modo real com "somente números de teste" bloqueia clientes | ✅ passou | 0.08s |
| 41 | mensagem de teste exige número da lista interna | ✅ passou | 0.06s |
| 42 | validação dos textos editáveis | ✅ passou | 0.05s |
| 43 | migração reaplicável sem perder configurações | ✅ passou | 0.06s |
| 44 | histórico financeiro e cadastros preservados (somente leitura) | ✅ passou | 0.09s |
| 45 | código do módulo não escreve em tabelas do painel | ✅ passou | 0.04s |
| 46 | descadastro: wa_id sem o 9º dígito, frases curtas, outro número ignorado, respostas registradas | ✅ passou | 0.05s |
| 47 | registrar envio manual antes das 09h impede o envio automático | ✅ passou | 0.07s |
| 48 | decisão manual recusada se o estado mudou no meio | ✅ passou | 0.07s |
| 49 | instruções de pagamento e rodapé não aceitam links nem senhas | ✅ passou | 0.06s |
| 50 | API rejeita formatos inválidos e cliente inexistente | ✅ passou | 0.05s |
| 51 | prévias usam a data coerente com cada etapa | ✅ passou | 0.05s |
| 52 | web: API sem sessão responde 401 em JSON (GET e POST) | ✅ passou | 0.12s |
| 53 | web: renovacoes.php sem sessão redireciona para o login e não mostra dados | ✅ passou | 0.08s |
| 54 | web: com login, página carrega com token CSRF e cabeçalhos de segurança | ✅ passou | 0.06s |
| 55 | web: mutação via GET → 405 e nada muda | ✅ passou | 0.07s |
| 56 | web: POST sem CSRF ou com CSRF errado → 403; com CSRF correto → 200 | ✅ passou | 0.07s |
| 57 | web: Origin/Referer de outro site → 403 mesmo com token válido | ✅ passou | 0.07s |
| 58 | web: logout invalida o acesso à API | ✅ passou | 0.06s |
| 59 | web: action=config e demais leituras não expõem segredos do config.php nem dados sensíveis dos pacotes | ✅ passou | 0.13s |
| 60 | web: salvar_config rejeita URL no texto e no rodapé e não grava | ✅ passou | 0.08s |
| 61 | web: previa devolve o texto renderizado (GET e POST com CSRF) | ✅ passou | 0.07s |
| 62 | web: painel agrupa dois pacotes do mesmo cliente na mesma data (total) e separa outra data | ✅ passou | 0.07s |
| 63 | web: executar_agora (ignorar_horario) cria mensagens SIMULADAS visíveis no histórico; repetir não duplica | ✅ passou | 0.12s |
| 64 | web: webhook GET verify — token certo devolve o challenge, errado 403 | ✅ passou | 0.06s |
| 65 | web: webhook POST com HMAC válido atualiza status; inválido 401; repetido é idempotente | ✅ passou | 0.09s |
| 66 | web: webhook "SAIR" assinado revoga a autorização do cliente | ✅ passou | 0.08s |
| 67 | web: nomes com HTML vêm como texto no JSON (escapados só na interface) e não quebram o JSON | ✅ passou | 0.11s |
| 68 | web (Chromium): sem erros de console/CSP, sem XSS, sem rolagem horizontal em 390px; botão Executar agora funciona | ✅ passou | 10.09s |
| 69 | web: servidor não registrou avisos/erros PHP durante os testes | ✅ passou | 0.06s |
