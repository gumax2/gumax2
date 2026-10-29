# Diagnóstico técnico — GMAX Ofertas (base atual × versão 3.0 P0)

## 1. O que foi analisado

| Material | Origem | Situação |
|---|---|---|
| `app.php`, `index.php`, `cron.php`, `.htaccess` | kit `base_inicial` | referência histórica |
| `index.php` corrigido (botão copiar) | kit `painel_v2_2` | referência histórica |
| `gmax-publicador-v2-1-cli.php` | kit `publicador_v2_1` | referência (instalado como `gmax-publicador-cli.php`) |
| `mercadolivre.php` | kit `painel_mercadolivre` | referência histórica |
| `gmax-ml-importar-cli.php` + modelo + CSV | kit `importador_mercadolivre` | referência histórica |
| Planilhas de 16 e 25 anúncios ML | kit `02_MercadoLivre` | dados reais de pesquisa |

**Importante:** os ZIPs recebidos não têm a cópia dos arquivos que estão **realmente instalados** na HostGator, e não houve acesso ao servidor. Por isso:

- a entrega é uma **prévia ainda não liberada para instalação**, até que você rode a verificação no servidor (veja a seção 5);
- o pacote **não substitui nenhum arquivo existente**: só adiciona arquivos novos;
- a página `Sistema` e o comando `verificar` comparam os seis arquivos do servidor com as versões de referência (por hash SHA-256) e mostram o que estiver **DIFERENTE**.

## 2. Achados na base atual (referências)

| # | Arquivo | Achado | Risco | Tratamento na 3.0 |
|---|---|---|---|---|
| 1 | `app.php` | `importFile()` usa `INSERT OR IGNORE`: um produto repetido é descartado sem aviso, e um preço novo nunca atualiza o antigo | Médio | Importador novo mostra a prévia linha a linha (novo, atualizar, sem alteração, rejeitado, com motivo) |
| 2 | `app.php` | Preço gravado como texto, sem preço original nem condição de pagamento | Médio | Catálogo grava valores em centavos, com De/Por, condição, fonte e data de conferência |
| 3 | `app.php` `slot()` | Com `$send=true`, quando o envio fica incerto a janela atual é marcada, mas a oferta continua `pendente` e seria repetida na próxima janela | **Alto** (só se ativado) | `cron.php` continua com `slot(db(), false)`, que só faz prévia. A fila nova pausa tudo quando há dúvida |
| 4 | publicador V2.1 | Travas boas: ativação dupla, mutex e pausa global com `attempted`/`uncertain`. Mas só atende Shopee e só ao `s.shopee.com.br` | Baixo | Mantido intacto. A fila 3.0 respeita as pendências dele e usa **o mesmo mutex**, então os dois nunca rodam ao mesmo tempo |
| 5 | `index.php` | Sem limite de tentativas de login, sem expiração de sessão e sem cabeçalhos de segurança | Médio | O painel novo bloqueia após 5 erros em 15 min, expira a sessão após 2 h ociosa e envia CSP, X-Frame-Options e nosniff. `index.php` não foi alterado |
| 6 | `mercadolivre.php` | Aceita qualquer domínio mercadolivre.com.br como "link afiliado" | Médio | No catálogo novo, link afiliado do ML precisa ser `https://meli.la/…`. Link comum é recusado |
| 7 | `gmax-ml-importar-cli.php` | Exige Cron temporário e CSV na pasta privada (uso do Gerenciador de Arquivos) | Operacional | Importação pelo navegador (CSV ou XLSX), com prévia e opção de desfazer |
| 8 | `.htaccess` | Protege `app.php` e `cron.php`. Os CLIs se protegem com `PHP_SAPI` | Baixo | `gmax-lib/` recebe `.htaccess` próprio (`Require all denied`), e cada template interno sai sem gerar nada se for acessado direto |
| 9 | Planilha de 16 candidatos | 14 links `meli.la` sem confirmação de produto, 2 inválidos e várias URLs de busca/categoria | Comercial | Importadas como **rascunho**. URLs de busca são descartadas e o produto continua bloqueado para publicação |
| 10 | Planilha de 25 anúncios | URLs individuais boas, mas sem link afiliado. Preços vêm de páginas indexadas | Comercial | Entram como rascunho com "preço não conferido". O link é associado depois (tela Links ou reimportação) |

## 3. Arquitetura da 3.0

- **Arquivos novos** dentro de `gmax-auto/`: `painel.php`, `gmax-admin-cli.php`, `gmax-lib/` e `gmax-assets/`. Nenhum arquivo existente é alterado.
- **Banco:** o mesmo SQLite, pela mesma função `db()` do `app.php`. A migração 001 cria só as tabelas `gmax3_*` e **copia** os produtos de `offers` e `gmax_ml_offers` para `gmax3_catalog`, guardando a referência da linha original. As tabelas antigas não são alteradas (teste automatizado compara o hash antes e depois).
- **Sessão:** usa a mesma do `index.php` (`$_SESSION['auth']` e `$_SESSION['csrf']`). Quem entra por um painel está logado no outro.
- **Duplicidade:** um produto é identificado por código GMAX, ID do anúncio (MLB/Item Id), link afiliado normalizado ou chave interna. Há índices `UNIQUE` no banco como última barreira.
- **Publicação:** fila `gmax3_pub_queue` com os estados `agendado → reservado → confirmado | falhou | incerto`. Para um envio real, **todas** estas condições precisam valer ao mesmo tempo:
  1. sem pausa (no painel ou pelo arquivo `gmax-publicacao.pause`);
  2. `automacao_comercial = 1`, que **só pode ser ligada pela linha de comando** e com frase de autorização;
  3. arquivo de ativação **próprio** `gmax3-publicacao-privada.php` válido, e o arquivo de ativação do V2.1 **ausente** (os dois publicadores nunca operam juntos);
  4. nenhuma pendência de reconciliação, nem na fila nova nem no V2.1;
  5. oferta aprovada, preço conferido dentro da validade, link oficial e condição de pagamento definida;
  6. regra do programa de afiliados ML: links ML em grupo privado ficam bloqueados por padrão.
- **Itens Shopee ainda `pendente` no V2.1** ficam bloqueados no 3.0 até o V2.1 ser oficialmente aposentado (comando com frase de confirmação). Assim o mesmo produto não pode ser enviado pelos dois.
- **Restaurar um backup pelo painel** volta só os dados do catálogo. Fila, configurações e auditoria não voltam no tempo, e a pausa é forçada.
- **Auditoria independente de segurança:** um agente revisor separado encontrou 2 problemas altos, 4 médios e 9 baixos. Todos os altos e médios foram corrigidos e ganharam testes. Dos baixos, só o limite de tentativas do `index.php` antigo não foi corrigido (ver LIMITACOES).
- **Nenhuma tela do painel envia mensagem.** A fila só é processada pela CLI (`fila:processar`), e não existe nenhum Cron dela no pacote.

## 4. Riscos e dependências

| Risco ou dependência | Como foi tratado |
|---|---|
| Arquivos do servidor diferentes das referências | Página Sistema e comando `verificar`, com diff por hash. Nada é sobrescrito |
| Esquema real das tabelas diferente | A verificação confere as colunas obrigatórias. As consultas às tabelas antigas checam as colunas antes. Formato desconhecido de `gmax_dispatches` **bloqueia** os envios |
| SQLite abaixo de 3.27 (o backup usa `VACUUM INTO`) | Aparece como FALHA na verificação, e a migração fica impedida |
| Servidor sem extensão `zip` | Aparece como aviso. Importar CSV continua funcionando |
| `.htaccess` não lido em subpastas (AllowOverride) | A guarda `defined('GMAX_VERSION')` cobre os templates. As classes só declaram código. Teste manual de acesso a `gmax-lib/` incluído na instalação |
| `app.php` de produção com efeitos colaterais (saída, sessão) | Se `painel.php` der erro 500, faça o rollback A (só apagar os arquivos novos) e envie o `app.php` sanitizado |
| API Shopee | Conector pronto (assinatura SHA256), aguardando as credenciais. Precisa conferir o endpoint e os campos na documentação entregue junto |
| API Mercado Livre | Conector só de consulta (preço, preço original, situação). **Não existe conversão automática de links afiliados** |

## 5. Pendências de verificação no servidor real

1. Rodar **Sistema → Verificação do ambiente** (ou `gmax-admin-cli.php verificar`) e conferir:
   - quais dos seis arquivos aparecem como **DIFERENTE** da referência;
   - se as colunas de `offers`, `gmax_ml_offers` e `users` existem;
   - a versão do SQLite e as permissões de `storage/` e `gmax-secrets/`.
2. Se algum arquivo estiver DIFERENTE, nada precisa ser mesclado, porque a 3.0 não substitui arquivos. Mesmo assim, guarde o resultado: ele documenta a versão real em produção.
