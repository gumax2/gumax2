# Resultado dos testes executados

Gerado automaticamente por `php tests/run.php` em 30/10/2026 09:06 (America/Sao_Paulo).

Ambiente: PHP 8.3.6, 10.11.14-MariaDB-0ubuntu0.24.04.1. Dados fictícios. Nenhuma chamada de rede.

**45 de 45 testes passaram.**

| # | Teste | Resultado | Tempo |
|---|---|---|---|
| 1 | cliente com dois pacotes e vencimentos diferentes gera lembretes separados | ✅ passou | 0.09s |
| 2 | vários pacotes na mesma data viram UMA mensagem com pacotes e valores | ✅ passou | 0.08s |
| 3 | preço total não é multiplicado pelos pontos | ✅ passou | 0.08s |
| 4 | ciclos trimestral e anual informam o valor do ciclo | ✅ passou | 0.09s |
| 5 | mensagens não contêm senha nem URL da lista | ✅ passou | 0.06s |
| 6 | renovação entre a geração e o envio cancela só aquele pacote | ✅ passou | 0.08s |
| 7 | renovação durante espera de nova tentativa cancela a mensagem | ✅ passou | 0.08s |
| 8 | renovação de um pacote não cancela avisos de outro pacote do cliente | ✅ passou | 0.08s |
| 9 | aviso de 1 dia após não sai se o pacote foi renovado | ✅ passou | 0.07s |
| 10 | cliente excluído, cliente pausado e pacote pausado não recebem | ✅ passou | 0.07s |
| 11 | cliente pausado no módulo e cliente excluído entre geração e envio | ✅ passou | 0.07s |
| 12 | sem consentimento não envia; autorização no mesmo dia libera; revogação bloqueia | ✅ passou | 0.07s |
| 13 | revogação entre a geração e o envio impede o envio | ✅ passou | 0.06s |
| 14 | telefone inválido ou alterado após autorização bloqueia | ✅ passou | 0.05s |
| 15 | normalização de telefones brasileiros | ✅ passou | 0.06s |
| 16 | ano bissexto: 29/02/2028 nas três etapas | ✅ passou | 0.12s |
| 17 | virada de mês e de ano | ✅ passou | 0.07s |
| 18 | fuso America/Sao_Paulo: antes das 09h nada é gerado | ✅ passou | 0.07s |
| 19 | fim da janela de envio: depois das 20h não envia | ✅ passou | 0.07s |
| 20 | ativar o módulo não dispara lembretes antigos | ✅ passou | 0.07s |
| 21 | ativação com data futura não gera nada antes dela | ✅ passou | 0.04s |
| 22 | lembretes pendentes de ontem expiram e não são enviados hoje | ✅ passou | 0.05s |
| 23 | agendamento repetido não reenvia | ✅ passou | 0.09s |
| 24 | trava impede execução simultânea no mesmo processo de teste | ✅ passou | 0.07s |
| 25 | três trabalhadores simultâneos (com trava do agendador) | ✅ passou | 1.09s |
| 26 | três trabalhadores simultâneos SEM a trava (reivindicação atômica) | ✅ passou | 0.50s |
| 27 | falha temporária: novas tentativas limitadas e depois "falhou" | ✅ passou | 0.11s |
| 28 | falha permanente não repete; erro de credencial ativa pausa global | ✅ passou | 0.08s |
| 29 | timeout gera "incerto" e não reenvia automaticamente | ✅ passou | 0.08s |
| 30 | envio interrompido (processo caiu) vira "incerto" | ✅ passou | 0.07s |
| 31 | reenvio manual após incerto só no mesmo dia | ✅ passou | 0.08s |
| 32 | callbacks repetidos e fora de ordem | ✅ passou | 0.07s |
| 33 | "entregue" só com callback; falha via callback antes da entrega | ✅ passou | 0.08s |
| 34 | callback antes de gravar o ID (órfão) é aplicado depois; incerto resolvido por referência | ✅ passou | 0.10s |
| 35 | webhook: assinatura inválida rejeitada e verificação GET | ✅ passou | 0.06s |
| 36 | descadastro por resposta SAIR revoga a autorização | ✅ passou | 0.08s |
| 37 | classificação de respostas da Meta | ✅ passou | 0.06s |
| 38 | payload do modelo da Meta (sem rede) | ✅ passou | 0.05s |
| 39 | modo simulação nunca usa provedor real | ✅ passou | 0.07s |
| 40 | modo real com "somente números de teste" bloqueia clientes | ✅ passou | 0.08s |
| 41 | mensagem de teste exige número da lista interna | ✅ passou | 0.07s |
| 42 | validação dos textos editáveis | ✅ passou | 0.05s |
| 43 | migração reaplicável sem perder configurações | ✅ passou | 0.07s |
| 44 | histórico financeiro e cadastros preservados (somente leitura) | ✅ passou | 0.12s |
| 45 | código do módulo não escreve em tabelas do painel | ✅ passou | 0.05s |
