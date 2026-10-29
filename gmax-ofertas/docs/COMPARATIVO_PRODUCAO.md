# Comparação: arquivos atuais da HostGator × projeto 3.0

Fonte: cópia sanitizada enviada em 29/10/2026 (sem banco de dados, logs ou credenciais). Os arquivos estão guardados em `desenvolvimento/referencia/producao-20261029/`. Os testes do pacote agora usam **esses arquivos reais**, e não mais os do kit.

## 1. Arquivo por arquivo

| Arquivo | Em relação ao kit | Impacto na 3.0 |
|---|---|---|
| `index.php` | **Idêntico** ao painel v2.2 | Login e sessão compartilhados com `painel.php` funcionam (testado) |
| `cron.php` | **Idêntico** (`slot(db(), false)`, só prévia) | Nenhum envio. Preservado |
| `.htaccess` | **Idêntico** | Preservado. `gmax-lib/` tem `.htaccess` próprio |
| `mercadolivre.php` | **Idêntico** | Continua mostrando a ML-001 depois da migração (testado) |
| `gmax-ml-importar-cli.php` | **Idêntico** | Preservado. A importação pelo painel o substitui no uso diário |
| `app.php` | **Diferente**: em `slot()`, token e chat agora vêm de `gmax-secrets/telegram.php`, com conferência do chat `-1004443626419`, no lugar das variáveis de ambiente | Compatível. As funções `db()`, `h()`, `valid()`, `importFile()`, `message()` e `slot()` são as mesmas. O envio por `slot()` continua exigindo `GMAX_TELEGRAM_ENABLED=true`, que não existe no Cron |
| `gmax-publicador-cli.php` | **Diferente: NÃO é o V2.1.** É uma versão anterior, sem duas proteções (veja a seção 2), com uma mensagem própria que **não mostra preço** ("Confira o preço atualizado…") | Ver seção 2 |

## 2. Conflito principal: publicador instalado × V2.1 × 3.0

O `gmax-publicador-cli.php` instalado **não tem** estas proteções do V2.1:

1. **Pausa global** quando existe uma janela em `attempted`/`uncertain`.
2. **Bloqueio de item já despachado**, ou seja, já presente em `gmax_dispatches`.

**Consequência, provada em teste:** com o arquivo de ativação presente, um envio incerto (timeout, por exemplo) deixa a oferta como `pendente`. Na janela seguinte, **o mesmo produto é enviado de novo**. O teste "RISCO CONFIRMADO" mostra 2 tentativas para o mesmo item.

**Hoje não há envio**, porque o arquivo de ativação está ausente e o publicador só simula. O risco aparece no dia em que ele for ativado.

### Como o pacote trata isso

| Medida | Onde |
|---|---|
| A fila 3.0 se recusa a operar se o arquivo de ativação do publicador antigo existir | `Queue::gates` |
| A fila 3.0 trata `attempted`/`uncertain` do antigo, e `slots=tentativa`, como pendência que bloqueia tudo | `Queue::unreconciled` |
| A fila 3.0 não envia item que o antigo já enviou ou registrou, nem item que ele ainda tem como `pendente` (a menos que você aposente o antigo) | `Rules::duplicate` |
| A pausa do painel também cria `gmax-publicacao.pause`, que o publicador antigo respeita | `Settings::pause` |
| Os dois usam o mesmo mutex (`gmax-publicador.mutex`), então nunca rodam ao mesmo tempo | `Config::lockFile` |
| **Revisão 2.2 opcional** do publicador antigo: a mesma mensagem instalada, mais as duas proteções do V2.1, mais respeito à pausa e às pendências do 3.0, mais a recusa de item que o 3.0 já tratou | `opcional/gmax-publicador-cli.php` |

### Revisões independentes

O agente revisor (surgeon-reviewer) analisou a revisão 2.2 e a convivência com a 3.0. Conclusão: a revisão 2.2 **nunca é menos segura** que a versão instalada. Ele apontou dois problemas, ambos corrigidos e testados:

1. Um produto importado no 3.0 **só com o link afiliado**, sem ID do anúncio, escapava das checagens cruzadas. Agora os dois publicadores também reconhecem o produto pelo **link normalizado** e pelo código, e a sincronização completa o ID que faltava.
2. A revisão 2.2 **travava** na primeira oferta já tratada pelo 3.0. Agora ela pula essa oferta e segue para a próxima.

**Risco residual:** a Shopee pode gerar links curtos diferentes para o mesmo produto. A única garantia completa é **manter um único publicador ativo**, nunca ativar os dois nem alternar entre eles no mesmo banco.

### Recomendação

- **Agora:** instale o painel 3.0. O publicador antigo pode continuar como está, porque sem ativação ele só simula.
- **Antes de qualquer ativação futura:** troque o publicador pela revisão 2.2 (com backup, passo opcional da instalação) **ou** aposente o antigo e use só a fila 3.0. Nunca ative os dois.

## 3. Compatibilidade verificada

| Item | Resultado |
|---|---|
| PHP 8.3 | Testes rodados em PHP 8.3.6. Sintaxe e funções compatíveis |
| SQLite | Exige 3.27 ou mais (backup `VACUUM INTO`). Conferido na verificação da instalação e pelo script de diagnóstico |
| Autenticação | Mesma tabela `users` e mesmas chaves de sessão (`auth`, `csrf`). Login cruzado `index.php` → `painel.php` testado |
| Agendadores | `cron.php` (prévia) e o publicador instalado (simulação sem ativação) testados depois da migração. Nenhum Cron é criado ou alterado |
| Dados | 5 Shopee, ML-001, `slots`, `gmax_dispatches` e `users` com hash idêntico antes e depois da migração. Links afiliados copiados sem alteração |

## 4. O que ainda não foi visto

A **estrutura real do banco**. Os arquivos indicam as tabelas `users`, `offers`, `slots`, `gmax_ml_offers` e `gmax_dispatches`, mas tabelas ou colunas extras só aparecem rodando `gmax-diagnostico-esquema.php` (passo 2 da instalação). O script mostra só a estrutura: nenhum título, link, preço, senha ou token.
