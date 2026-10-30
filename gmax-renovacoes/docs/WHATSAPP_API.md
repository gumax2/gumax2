# WhatsApp: provedor, custos, coexistência, modelos e consentimento

> Informações conferidas em 30/10/2026 em fontes públicas. Regras e preços da Meta mudam; **confira os links oficiais antes de ativar.** Nada aqui presume aprovação de modelos ou categoria.

## 1. Provedor

**API oficial: WhatsApp Business Platform — Cloud API (Meta).** É a única forma oficial de envio automático pelo servidor. O módulo já traz o conector `MetaCloudProvider` (envio de modelo aprovado + webhook assinado).

Requisitos:
- Conta no **Meta Business** (Gerenciador de Negócios), de preferência verificada.
- Um **app** no Meta for Developers com o produto WhatsApp.
- Uma **conta do WhatsApp Business (WABA)** e um **número** conectado à Cloud API.
- **Token de Usuário do Sistema** (permanente) com permissões `whatsapp_business_messaging` e `whatsapp_business_management`.
- **App Secret** (para validar a assinatura do webhook) e um **verify token** escolhido por você.
- **Forma de pagamento** cadastrada na WABA (mensagens de modelo são cobradas).
- **Modelos de mensagem aprovados** (ver item 4).

O aplicativo **WhatsApp Business** sozinho (o que você usa hoje) **não tem API**. Botões "Abrir WhatsApp com mensagem pronta" (`wa.me`) exigem que **você toque em Enviar** — é envio manual, não automático. A aba oferece esse botão apenas como apoio manual.

## 2. Manter o número atual e o aplicativo (coexistência)

A Meta oferece a **coexistência**: o mesmo número continua no aplicativo WhatsApp Business **e** passa a ser usado pela Cloud API. Você **não** precisa excluir sua conta.

Requisitos e limitações (documentação da Meta, "Onboarding WhatsApp Business app users"):
- App WhatsApp Business na versão **2.24.17 ou superior**, número em uso ativo no app.
- O cadastro é feito pelo **Embedded Signup** de um **Parceiro de Soluções (BSP) ou Tech Provider** — não é algo que se ativa sozinho no app.
- Vazão fixa de **20 mensagens/segundo** (sem impacto para este uso).
- Mensagens enviadas **pelo app continuam gratuitas**; as enviadas **pela API** são cobradas.
- Aparelhos acompanhantes são desconectados no processo; alguns (ex.: Windows, WearOS) não são suportados.
- Histórico de até 6 meses pode ser sincronizado; alguns países/regiões não são elegíveis.

**Decisão que é sua** (ver `PENDENCIAS.md`):
1. **Coexistência via BSP** — mantém o número que os clientes já conhecem. O BSP escolhido pode usar a Cloud API diretamente (funciona com o conector atual) ou um endpoint próprio (aí é preciso um conector pequeno para ele; a estrutura `Provider` já está pronta para isso).
2. **Número novo direto na Cloud API** — mais simples e funciona com o conector atual, mas os lembretes saem de outro número.

Antes de escolher, confirme com o BSP: suporte a coexistência no Brasil, se o token/endpoint é o da própria Meta, se repassa os webhooks com a assinatura `X-Hub-Signature-256`, e a taxa mensal dele.

## 3. Custos verificáveis

- Desde **1º/07/2025** a Meta cobra **por mensagem de modelo entregue**, com preço por **categoria** (marketing, utilidade, autenticação) e país.
- Mensagens de **utilidade** enviadas **dentro de uma janela de atendimento aberta** (cliente escreveu nas últimas 24h) são **gratuitas**.
- A Meta anunciou **preços em reais (BRL)** para o Brasil a partir de 2026. **Consulte a tabela oficial:** https://developers.facebook.com/docs/whatsapp/pricing
- Valores publicados por terceiros para "utilidade" no Brasil variam (fontes conflitantes); **não use para orçamento** sem conferir a tabela oficial.
- BSPs costumam cobrar **mensalidade e/ou margem** por mensagem, à parte da Meta.

**Como estimar:** `nº de pacotes por mês × nº de avisos efetivamente enviados (até 3) × preço da categoria`. Ex.: 100 pacotes × 2 avisos em média = 200 mensagens/mês × preço oficial de utilidade. Pacotes renovados antes do vencimento recebem menos avisos.

A categoria é decidida pela **Meta** na análise. Lembrete de vencimento com valor e instrução de pagamento costuma ser enquadrado como **utilidade**, mas pode ser reclassificado como **marketing** (mais caro) se o texto tiver tom promocional. Os textos propostos evitam ofertas e chamadas comerciais.

## 4. Modelos a cadastrar (6 no total)

Mensagens iniciadas pela empresa precisam de **modelo aprovado**. Cadastre no Gerenciador do WhatsApp, idioma **Português (BR) — `pt_BR`**, categoria sugerida **Utilidade**. Variáveis numeradas na ordem abaixo (a mesma que o módulo envia):

| Etapa | Variante | Nome sugerido | Variáveis |
|---|---|---|---|
| 3 dias antes | um pacote | `gmax_renovacao_antes` | {{1}} nome · {{2}} pacote · {{3}} data · {{4}} valor · {{5}} instruções |
| 3 dias antes | vários pacotes | `gmax_renovacao_antes_grupo` | {{1}} nome · {{2}} data · {{3}} lista de pacotes · {{4}} total · {{5}} instruções |
| no dia | um / vários | `gmax_renovacao_dia` / `gmax_renovacao_dia_grupo` | idem |
| 1 dia após | um / vários | `gmax_renovacao_depois` / `gmax_renovacao_depois_grupo` | idem |

Exemplo do corpo para `gmax_renovacao_antes` (adaptado do seu texto):

```
Olá, {{1}}! Seu pacote {{2}} vence em {{3}}, daqui a 3 dias.

O valor da renovação é {{4}}.

Já estamos passando para você se organizar. Para renovar: {{5}}

Após pagar, envie o comprovante por aqui para confirmarmos. Se já renovou, fale conosco para conferirmos o cadastro.
```
Rodapé do modelo (texto fixo): `Para não receber mais estes lembretes, responda SAIR.`

Regras da Meta que o módulo já respeita: parâmetros **sem quebra de linha** (a lista de pacotes vai em uma linha, separada por "; "), corpo até 1024 caracteres, sem links. Emojis no texto fixo do modelo são permitidos, mas evite excesso.

**Importante:** no envio real, o texto que chega ao cliente é o **do modelo aprovado**; os textos editáveis da aba servem para prévia, simulação e envio manual. Mantenha-os iguais ao modelo. Depois da aprovação, preencha os nomes na aba **Configurações → Modelos aprovados**.

## 5. Consentimento (autorização) e descadastro

- Só recebe lembrete quem tem autorização registrada na aba **Autorizações** (com a origem: ex. "respondeu SIM no WhatsApp em 30/10/2026"). A autorização fica vinculada ao telefone; se o telefone mudar no cadastro, é preciso registrar de novo.
- Sugestão para obter a autorização pelo próprio app: *"Podemos te avisar por WhatsApp quando seu pacote estiver perto de vencer? Responda SIM para receber. Você pode pedir para parar a qualquer momento respondendo SAIR."*
- **Revogação:** pelo botão na aba, ou automaticamente quando o cliente responde uma mensagem curta com **SAIR / PARAR / PARE / STOP / DESCADASTRAR** ou "não quero mais receber" (via webhook). Tudo fica registrado com data em `gr_consentimento_log`. Respostas dos clientes (ex.: comprovantes) aparecem em Histórico → "Respostas recebidas" — no número só da API não há aplicativo para lê-las.
- O **rodapé de descadastro precisa estar no texto aprovado de cada modelo**: no envio real, só o modelo aprovado chega ao cliente.
- Pausa por cliente (sem revogar) e pausa global também ficam na aba.

## 6. Webhook

URL a informar no app da Meta: `https://gmaxcanais.com.br/painel/renovacoes-webhook.php`
Campo a assinar: **messages**. Verify token: o mesmo do `config.php`. As requisições sem assinatura válida (`X-Hub-Signature-256` com o App Secret) são recusadas.

> Se a pasta `/painel/` tiver proteção por senha no `.htaccess` (autenticação HTTP), a Meta não alcançará o webhook. **Não altere o `.htaccess`**: nesse caso, me envie a informação e eu indico onde colocar o arquivo do webhook (ele pode ficar em outra pasta pública; só precisa do `renovacoes-caminho.php` apontando para a biblioteca).

## Fontes

- [Pricing on the WhatsApp Business Platform (Meta)](https://developers.facebook.com/docs/whatsapp/pricing)
- [WhatsApp Business Platform — Pricing (documentação nova da Meta)](https://developers.facebook.com/documentation/business-messaging/whatsapp/pricing)
- [Onboarding WhatsApp Business app users / coexistência (Meta)](https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/onboarding-business-app-users)
- [WhatsApp Coexistence — 360dialog (exemplo de BSP)](https://docs.360dialog.com/docs/hub/embedded-signup/whatsapp-coexistence)
- [Guia de webhooks do WhatsApp — Hookdeck (assinatura e eventos)](https://hookdeck.com/webhooks/platforms/guide-to-whatsapp-webhooks-features-and-best-practices)
