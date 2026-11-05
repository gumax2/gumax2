# Relatório de homologação — GMAX AutoLab (3.2.1)

## 1. Situação atual: NÃO homologado em produção

| Etapa | Situação | Evidência |
|---|---|---|
| Testes automatizados (ambiente de desenvolvimento, sem rede, Telegram falso) | **Executados e aprovados** | `TESTES.md` (suítes `autolab_test.php`, `v321_test.php`, `http_test.php`, `ui_test.php`) |
| Instalação na HostGator | **Pendente** (você) | — |
| Validação real do destino (`autolab validar-destino`) | **Pendente** | Saída do comando |
| Prévia real com a API Shopee (`autolab previa`) | **Pendente** | Texto exibido |
| Primeiro envio real ao grupo privado | **Pendente**: só depois da ativação | Mensagem no grupo e página AutoLab |
| `validar-retomada` na HostGator | **Pendente** | `"aprovado": true` |
| Operação autônoma por 7 dias | **Pendente** | Tabela da seção 3 |

Os testes simulados **não** validam: a resposta real do Telegram, o tempo real das APIs, o Cron real da HostGator, nem as regras dos programas de afiliados.

## 2. O que os testes automatizados comprovaram (simulação)

- **Destino:**
  - só `-1004443626419`;
  - outro chat ou texto sem rótulo são recusados sem requisição;
  - grupo público, canal, membros demais ou bot fora levam a "falha", sem envio.
- **Isolamento:**
  - fila, catálogo, histórico e travas comerciais idênticos antes e depois de vários envios do AutoLab;
  - o processador comercial continua bloqueado.
- **Conteúdo:**
  - oferta validada só com preço confirmado agora pela API, sem "De" nem desconto;
  - candidato e simulação sem links;
  - Mercado Livre sem link afiliado, com o ID de catálogo separado do anúncio;
  - preço antigo não é usado como atual.
- **Robustez:**
  - 6 processos ao mesmo tempo geram 1 envio;
  - processo morto no meio do envio fica "incerto" e não reenvia;
  - timeout fica "incerto", e 2 seguidos pausam o AutoLab;
  - pausa no meio da preparação: nada é enviado;
  - limite diário, intervalo mínimo e fim do período respeitados.

## 3. Acompanhamento da semana (preencher no servidor)

Fonte: página **AutoLab → Relatório diário** ou `php gmax-admin-cli.php autolab status`.

| Dia | Enviadas | Incertas | Falhas | Bloqueadas | Tipos | Conferido no grupo? | Observações |
|---|---|---|---|---|---|---|---|
| 1 | | | | | | | |
| 2 | | | | | | | |
| 3 | | | | | | | |
| 4 | | | | | | | |
| 5 | | | | | | | |
| 6 | | | | | | | |
| 7 | | | | | | | |

**Critério para considerar homologado:**
- os 7 dias rodaram sem intervenção;
- todas as mensagens chegaram rotuladas;
- nenhum envio fora dos horários ou acima do limite;
- incertos, se houver, conferidos no grupo;
- nenhuma mudança na fila ou nas travas comerciais;
- a pausa de emergência foi testada uma vez.

## 4. Defeitos encontrados durante a homologação

| Data | Ref. AutoLab | O que aconteceu | Ação |
|---|---|---|---|
| | | | |
