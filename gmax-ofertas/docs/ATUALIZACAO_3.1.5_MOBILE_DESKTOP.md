# GMAX Ofertas 3.1.5: Mobile & Desktop

**Base:** 3.1.4 instalada. **Banco:** sem migração e sem tabela nova; só arquivos de interface e uma regra nova de conferência manual.
**Não alterado:** autenticação, sessões, CSRF, CSP (continua restritiva), OAuth do Mercado Livre, integração Shopee, produtos, links afiliados, histórico de preços, histórico das automações, backups, publicadores e travas, Cron, permissões, tokens e credenciais.
**Continua desligado:** envios pausados, aprovação automática desligada, nenhuma publicação habilitada.

## 1. O que muda

### Interface responsiva (mobile-first, de 320 px a monitores grandes)
- **Celular:**
  - Cabeçalho compacto com botão ☰ Menu, marca, selo "Pausado", Sair e busca global.
  - O menu abre como gaveta: fecha com Esc, ao tocar num item ou no botão, e o foco vai para o menu.
  - Sem rolagem horizontal da página. Botões com 44 px de altura (36 px nos pequenos).
  - Campos com fonte de 16 px, o que evita o zoom automático do iPhone. Áreas seguras do iPhone respeitadas.
- **Tablet (768 px ou mais):** tabelas completas e filtros abertos.
- **Notebook e desktop (1024 px ou mais):** **menu lateral fixo** e busca no topo.
- **Monitores grandes (1600 px ou mais):** área útil mais larga e grade de 3 colunas.
- **Tabelas no celular** viram cartões, com o nome da coluna em cada campo. Isso vale para Automações, Execuções, Publicações e Importações. Os rótulos são aplicados pelo JavaScript, sem alterar as páginas existentes.
- **Filtros recolhíveis:** no celular ficam recolhidos quando não há filtro ativo e mostram a contagem de filtros ativos. No desktop ficam sempre abertos.
- **Feedback:** os botões mostram estado de carregamento, os alertas são legíveis e há um aviso de **"Sem conexão"**. Sem rede, nenhum formulário é enviado nem se perde.
- **Acessibilidade:**
  - Link "Pular para o conteúdo" e foco visível.
  - `aria-expanded` no menu, `aria-current` na página ativa e `aria-live` nas mensagens.
  - **Sem JavaScript**, o menu fica sempre visível (`sem-js.css`).

### Abrir anúncios nas lojas
- **🛍️ Conferir na Shopee / 🛒 Conferir no Mercado Livre** (botão cheio, na cor da loja) abre a **URL original oficial** do anúncio:
  - Só `https` de `shopee.com.br` ou `produto.mercadolivre.com.br`, validada pelo servidor.
  - É um link comum em nova aba (`rel="noopener noreferrer"`), sem redirecionamento intermediário e sem esquemas internos de aplicativo.
  - Assim, o iOS (Universal Links) e o Android (App Links) podem abrir o **aplicativo oficial** quando ele está instalado e o sistema permite. Caso contrário, abre no navegador.
- **🔗 Abrir link afiliado** (contorno tracejado, visualmente diferente) abre o link oficial de divulgação (`s.shopee.com.br`, `meli.la`). O painel avisa que abrir esse link registra um clique de afiliado.
- **Sem URL ou sem link:** os botões aparecem desativados ("Sem URL do anúncio", "Sem link afiliado"), e nenhum link é inventado. O link afiliado **nunca** é trocado pelo link comum.

### Conferência rápida de preços (tela nova "Conferência rápida")
- **Botão grande** para abrir o anúncio, com o lembrete: "Abrir o anúncio **não** confirma o preço".
- **Situação atual** do produto: Por, De, condição, disponibilidade, data da última conferência e **origem**:
  - selo **API Shopee / API Mercado Livre** para validação automática;
  - selo **Manual** para conferência feita por uma pessoa.
- **Registro do que foi encontrado:**
  - preço Por (obrigatório) e De, com desconto calculado na hora;
  - condição em botões de toque (Normal, Pix, Cartão, Cupom, Outra) e detalhe;
  - disponibilidade;
  - variação conferida, com a opção "variação definida" quando há pendência;
  - vendedor e observações.
- **Salvar conferência** exige marcar **"Conferi agora, na loja…"**. Sem essa marcação o servidor recusa e **nada é gravado**. A recusa também vale para requisições forjadas fora do navegador.
- **Registro:** fica como **conferência manual** (usuário + fonte `conferencia_manual`), **nunca** como validação pela API. Preço alterado retira a aprovação, como já acontecia. As observações entram nas notas internas com data, e a auditoria registra a conferência.

### Ofertas
- **Celular, em cartões:**
  - ícone da loja, nome, marketplace, estado e preço atual;
  - **preço anterior conferido**, quando existe, e desconto "validado" ou "não conferido";
  - data e origem da última conferência;
  - link afiliado presente ou ausente;
  - alertas de **variação pendente** e **indisponível**.
- **Ações rápidas do cartão:** Conferir preço, Editar, Conferir na loja, Abrir link afiliado, **Copiar mensagem** e **Arquivar** (com confirmação).
- **Desktop:** tabela completa com seleção múltipla, ações em lote (com confirmação) e botões de conferir e abrir na loja em cada linha.
- **Filtros novos:** disponibilidade e variação pendente.
- **Navegação:** filtros guardados durante a sessão, **"← Voltar aos resultados"** no produto e na conferência, e retorno à mesma posição da lista.

### Visão geral (dashboard)
- **Indicadores clicáveis**, que abrem a lista já filtrada:
  - total, Shopee, Mercado Livre, rascunhos, aguardando validação;
  - preços desatualizados, sem link afiliado, anúncios indisponíveis, variação pendente.
- **Melhores oportunidades comerciais**, com o atalho "conferir preço".

### Automações, importação e confirmações
- **Automações e Execuções:** cartões no celular. Os agendamentos **não** foram alterados.
- **Confirmação explícita:** pausa geral (já existia), retomada (digitar RETOMAR, já existia), **aprovar produto**, **adicionar à fila de publicação**, **ações em lote**, **cancelar publicação agendada**, **arquivar** e **confirmar preço**.
- **Importação:** a seleção de arquivo funciona no iPhone e no Android e mostra o nome, o tamanho e um aviso acima de 5 MB. A prévia, a detecção de duplicados e o resultado por produto já existiam e foram mantidos, inclusive a importação de links afiliados em lote.

### PWA ("Adicionar à tela inicial")
- **Avaliação:** viável **sem service worker**.
  - O Chrome no Android e o Safari no iPhone instalam pelo manifesto e pelos ícones.
  - Sem service worker não existe cache: **nenhuma página autenticada, credencial ou token é armazenado offline**. Esse era o principal risco de segurança do PWA, e ficou eliminado.
- **Arquivos:** `gmax-assets/manifest.json` (nome GMAX, modo standalone, cores do tema) e os ícones 192, 512, maskable e `apple-touch-icon` (180).
- **Tela de abertura:** no Android é gerada pelo próprio sistema a partir do manifesto. No iPhone aparece a tela padrão do sistema; não foram geradas imagens de abertura específicas por modelo.
- **Links externos no app instalado:** os botões das lojas abrem fora do app, no aplicativo da loja ou no navegador. O painel continua aberto e você volta a ele pelo alternador de apps.
- **Sem internet:** o app instalado mostra o erro normal do navegador. Não há modo offline, e isso é intencional.

## 2. Testes realizados (resultado real em `TESTES.md`)

| Suíte | O que cobre |
|---|---|
| Interface no navegador (`tests/ui_test.php` + `tests/browser_ui.js`) | 9 perfis × 15 páginas: rolagem horizontal, erros PHP/JS, CSP. Também menu, teclado, tamanho de toque e fonte, cartões × tabela, filtros, busca, botões das lojas (URL, nova aba, `noreferrer`, diferenciação), produto sem URL e sem link, abrir e voltar sem confirmar, variação, automações em cartões, conferência manual completa, voltar aos resultados, importação pelo celular, arquivar com confirmação, copiar mensagem, indicador sem rede, manifesto e ícones, ausência de service worker e integridade final no banco |
| Conferência (`tests/conferencia_test.php`) | Regras do servidor: confirmação obrigatória, registro manual e não API, aprovação retirada, link preservado, validações, variação, botões só com URLs oficiais, filtros novos |
| HTTP (`tests/http_test.php`) | `offer_check` com CSRF, sem login e sem confirmação; páginas novas; manifesto; CSP |
| Demais suítes (unidade, compatibilidade, links, automações, OAuth, execuções, Shopee) | Regressão completa |

**Perfis emulados:**
- Android 320 px (Chrome);
- iPhone SE 375 px (Safari);
- iPhone 13 390 px (Safari e Chrome iOS);
- Pixel 7 412 px (Chrome);
- iPad Mini 768 px;
- notebook 1024 px (Chrome);
- desktop 1366 px (Edge);
- monitor 1920 px (Chrome).

**Limitações dos testes:**
- Não há aparelhos físicos nem Safari/WebKit real neste ambiente. Todos os testes rodaram no **Chromium com emulação**: viewport, densidade de tela, toque e user agent do iPhone, do Android e do Edge.
- A abertura do **aplicativo** da Shopee ou do Mercado Livre depende do sistema do aparelho e não pode ser emulada. O teste confirma o link oficial `https` em nova aba, que é o que permite ao sistema abrir o app.
- Recomendo uma conferência rápida no seu iPhone e no seu Android depois da instalação (passo 5 abaixo).

**Capturas** (pasta `capturas/` do pacote):
- `iphone-390-*`, `android-412-*`, `tablet-768-*` e `desktop-1366-*`, para as páginas painel, ofertas, conferir e automações;
- `*-menu.png`, com o menu aberto no celular e no tablet.

## 3. Instalação na HostGator

1. **Backup:** em Sistema, clique em **Criar backup agora**. Depois, compacte `painel.php`, `gmax-lib` e `gmax-assets` em `gmax-secrets/gmax-arquivos-3.1.4-AAAAMMDD.zip`.
2. **Envio:** extraia `GMAX_Ofertas_3.1.5_incremental_arquivos.zip` em `/home1/gust1922/gmaxcanais.com.br/gmax-auto/` e confirme a substituição. A lista completa, com os arquivos novos de `gmax-assets/` (manifesto, ícones, `sem-js.css`) e `gmax-lib/pages/conferir.php`, está no `MANIFESTO.txt`.
3. **Sem migração:** o painel abre direto.
4. **Recarregue sem cache:** Ctrl+F5 no computador. No celular, feche e reabra a aba. O rodapé deve mostrar **3.1.5**.
5. **Conferência nos aparelhos:**
   - **iPhone (Safari):** abra o painel, toque em ☰ Menu, abra Ofertas, depois um produto, depois **Conferir na Shopee**. O app da Shopee deve abrir se estiver instalado. Volte, preencha e **salve a conferência**.
   - **Instalar no iPhone:** Compartilhar → **Adicionar à Tela de Início**.
   - **Android (Chrome):** o mesmo fluxo. Para instalar, use menu ⋮ → **Instalar app** ou **Adicionar à tela inicial**.
6. **Envios:** confira que o cabeçalho continua mostrando **"Envios pausados"**.

## 4. Rollback

Extraia o backup de arquivos do passo 1 e recarregue com Ctrl+F5. Sem migração, o banco não precisa de nada. As conferências manuais salvas na 3.1.5 continuam válidas, porque usam os campos que já existiam. Se o app foi instalado no celular, basta removê-lo da tela inicial.
