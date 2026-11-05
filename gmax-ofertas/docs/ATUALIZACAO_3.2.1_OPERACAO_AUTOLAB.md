# GMAX Ofertas 3.2.1: operação (índices, monitor, retenção) e GMAX AutoLab

**Base:** 3.2.0 instalada.
**Banco:** duas migrações **independentes**, aplicadas por você em Sistema → MIGRAR, com backup automático antes:
- **005 (operação):** só **cria** índices, a tabela de registro da retenção e gatilhos de proteção. **Nenhuma linha é apagada.**
- **006 (AutoLab):** só **cria** as duas tabelas do AutoLab, que nasce **desativado**.

Cada uma tem reversão própria: dá para remover só o AutoLab e manter a 005.

**Não alterado:**
- Cron, credenciais, OAuth do Mercado Livre, Shopee, produtos, links, histórico e auditoria;
- arquivos antigos: `app.php`, `index.php`, `mercadolivre.php`, `cron.php` e o publicador.

**Publicação comercial:** continua pausada, com aprovação automática desligada e publicadores antigos desativados. O AutoLab **não libera nenhuma dessas travas**.

> Você pediu para juntar a 3.2.1 e o AutoLab: os dois vão num único pacote, mas com migrações, testes e rollback separados.

---

## 1. Avaliação de viabilidade e riscos do AutoLab

**Viável, com o desenho abaixo.** Riscos de manter o AutoLab rodando durante o desenvolvimento:

| Área | Risco | Tratamento |
|---|---|---|
| Segurança | Um teste liberar envio comercial ou para outro grupo | O transporte do AutoLab só aceita o supergrupo `-1004443626419`, fixo no código, e textos rotulados TESTE ou SIMULAÇÃO. Qualquer outro caso é recusado **sem requisição**. A autorização do AutoLab é outra frase, guardada em outras chaves, e não satisfaz nenhuma trava comercial. O AutoLab não usa a fila comercial, o processador nem o publicador antigo (teste automático confere no código). |
| Destino | O grupo deixar de ser privado | Antes de **cada** envio, o AutoLab consulta `getChat`, `getChatMemberCount` e `getChatMember`. Se o grupo tiver @username público, não for grupo, passar de 10 membros ou o bot não for membro, o AutoLab entra em **falha**. Esse estado só se retoma pela CLI. |
| Afiliados | Cliques de teste nos links | Conforme sua decisão: link afiliado **só da Shopee**, e só na mensagem OFERTA VALIDADA. Mercado Livre nunca leva link afiliado. Candidatos e simulações não levam **nenhum** link. As regras dos programas não puderam ser verificadas daqui: confirme você mesmo, nos termos da Shopee, o uso no grupo de testes. |
| Envio incerto | Timeout ou rede no envio | Fica "incerto" e **nunca é reenviado**. O AutoLab segue nos próximos horários e **pausa sozinho após 2 incertos seguidos**. |
| HostGator | Consumo | Baixo. São até 2 mensagens por dia e, por horário, 4 consultas de leitura ao Telegram e até 3 à Shopee. Roda dentro da chamada do Cron que já existe (`executar-pendentes`), sem Cron novo. |
| Estabilidade | Instalar uma versão no meio de um envio | Pause o AutoLab antes de instalar e retome depois. Um processo interrompido vira "incerto" (sem repetição) no ciclo seguinte. |
| Banco | Crescimento | São 2 linhas por dia (execução e mensagem), protegidas contra exclusão. Ao longo do ano é desprezível. |
| Versões futuras | Conflito de migração | A 005 e a 006 são independentes e numeradas, e as próximas versões seguem a partir da 007. |

**Melhorias que tornam os testes mais úteis:**
- uma SIMULAÇÃO de formatação com casos-limite: título longo, sem "De", acentos;
- registro do preço do catálogo comparado com o da API (detecta mudança de preço);
- relatório diário por tipo e resultado;
- "Ref. AutoLab AAAA-MM-DD HH:MM" em cada mensagem, para conferir no grupo;
- prévia pela CLI (`autolab previa`), que monta a próxima mensagem sem enviar.

## 2. Arquitetura do AutoLab

```
Cron existente (a cada 15 min) → gmax-automacoes-cli.php executar-pendentes
   ├─ automações comerciais (como antes, nenhuma envia mensagem)
   └─ AutoLab::tick  (trava própria gmax-autolab.mutex; estado e pausa próprios)
        1. recupera processos interrompidos: reservado → incerto (não reenvia); preparado → falhou (nada enviado)
        2. estado ativo? sem arquivo de pausa? dentro do período (7 dias)?
        3. horário vencido (10:00 ou 18:00 de Brasília, até 2 h de atraso) ainda não registrado?
           → registra o horário com chave ÚNICA (dois Crons ao mesmo tempo = um envio)
        4. limite diário (2) e intervalo mínimo (60 min)
        5. valida o destino no Telegram (somente leitura)
        6. Composer: escolhe o produto e monta a mensagem (somente leitura sobre o catálogo)
           A. Shopee com preço único confirmado AGORA pela API, variação definida, não indisponível,
              link afiliado s.shopee.com.br válido  → TESTE — OFERTA VALIDADA
           A'. mesmo produto faltando algo         → TESTE — CANDIDATO (lista o que falta, sem link)
           B. candidato do catálogo ML / produto ML → TESTE — CANDIDATO (ID de catálogo ≠ anúncio, sem link)
           C. nada disponível                      → SIMULAÇÃO (dados fictícios, sem link)
        7. última barreira de conteúdo (rótulo, links permitidos, tamanho)
        8. reserva (transação; pausa feita há instantes vence) → envia → grava o resultado do Telegram
```

| Tipo | Quando | Preço | "De"/desconto | Link |
|---|---|---|---|---|
| TESTE — OFERTA VALIDADA | Todos os requisitos A | Confirmado agora pela API Shopee, com data | Nunca: a API Shopee não informa o preço anterior | Afiliado Shopee |
| TESTE — CANDIDATO | Falta algum dado | Só se confirmado agora. Do ML, só com menos de 24 h e marcado "NÃO conferido" | Nunca | Nenhum |
| SIMULAÇÃO | Sem produto real | "Preço fictício" | Nunca | Nenhum |

**Isolamento.** O AutoLab:
- não lê nem grava `gmax3_pub_queue`;
- não altera catálogo, preço, histórico ou link;
- não usa `Queue::processNext` nem o publicador antigo;
- não muda `publicacao_pausada`, `automacao_comercial`, `aprovacao_automatica` nem o arquivo de ativação.

Os testes conferem isso por **impressão digital** de tudo o que é comercial, antes e depois de vários envios, e por varredura do código.

**Estados:**

| Estado | Como se chega | Como sai |
|---|---|---|
| desativado | Estado inicial | CLI: `validar-destino` e depois `ativar` |
| ativo | Após a ativação | Pausa (botão ou CLI), falha ou fim do período |
| pausado | Botão de emergência, arquivo `gmax-autolab.pause` ou 2 incertos seguidos | Retomar pelo painel ou pela CLI |
| falha | Destino recusado, credencial ausente, Telegram 401/403/404 | **Só pela CLI**: `validar-destino` e depois `retomar` |
| encerrado | Fim do período autorizado | CLI: `ativar`, que renova o período |

## 3. O que mais a 3.2.1 traz (operação)

- **Índices (005):** escolhidos por `EXPLAIN QUERY PLAN` das consultas reais. Cobrem:
  - lista de ofertas e assinatura do cache;
  - histórico conferido do Ranking;
  - diagnóstico das tarefas;
  - datas usadas na retenção;
  - relatório da fila.
- **SQLite sem WAL** (modo atual mantido):
  - até 8 MB de cache por conexão e temporários em memória;
  - linhas informativas da revisão ("fora da revisão" e "não processado") gravadas **num único lote**, em vez de uma gravação por linha.
- **Diagnóstico** (menu Diagnóstico):
  - saúde: Cron, execuções e travas, falhas e alertas, integrações, banco (tamanho, páginas livres, modo de diário), integridade sob demanda, disco, backups, permissões e usuário do PHP;
  - diagnóstico de cada tarefa: última execução, duração, resultado da semana, próxima execução e continuação pendente.
- **Retomada imediata:** uma execução interrompida pelo servidor é fechada no **Cron seguinte**, sem esperar 15 min, com alerta. A continuação pula o que já foi consultado.
- **Validação da retomada na hospedagem:** `gmax-automacoes-cli.php validar-retomada`.
  - Usa uma **cópia temporária** do banco e nenhuma API.
  - Um processo PHP real é encerrado à força no meio e outro retoma.
  - O resultado é aprovado ou reprovado, e a cópia é apagada.
- **Retenção (somente simulação automática):**
  - Elimináveis: só os **logs operacionais detalhados** com mais de 90 dias (detalhe por produto das execuções e registro de descobertas).
  - **Preservados:** auditoria (agora somente-inclusão por gatilho), publicações e reconciliações, histórico de preços, catálogo, importações, alertas, resumo das execuções, AutoLab e backups.
  - A simulação grava a lista **exata** (tabela, ID e data) num arquivo privado.
  - A execução só pela CLI exige:
    - a frase `EXCLUIR_REGISTROS_OPERACIONAIS_SIMULADOS`;
    - uma simulação com menos de 24 h;
    - um backup verificado e um **teste de recuperação** no próprio backup;
    - uma exportação completa, verificada por releitura.
  - A exclusão roda numa única transação. Linhas criadas depois da simulação **não** são apagadas.
  - **Nenhuma exclusão foi feita nem será feita sem você rodar esse comando.**

## 4. Permissões 0644 → 0600 e 0755 → 0700 (recomendação)

Na HostGator compartilhada (cPanel), o PHP do site normalmente roda **com o mesmo usuário da conta** (`gust1922`), via suEXEC/PHP-FPM, e o Cron também. Nesse caso, 0600 no banco e 0700 em `gmax-secrets` são seguros e impedem a leitura por outros usuários do servidor.

Isso precisa ser **confirmado**, não presumido. Se o PHP do site rodasse como outro usuário (por exemplo `nobody`), restringir faria o painel parar de abrir o banco.

**Procedimento seguro:**
1. Abra **Diagnóstico** pelo navegador uma vez. A página registra o usuário do PHP do site e mostra:
   - "Permissão do banco";
   - "Permissão de gmax-secrets";
   - "Usuário do PHP do site", que precisa estar igual ao dono do banco.
2. Pelo Cron temporário ou SSH: `php gmax-admin-cli.php permissoes`. Isso **só mostra** o plano e se é seguro aplicar.
3. Se aparecer `"seguro_aplicar": true`, rode `php gmax-admin-cli.php permissoes --aplicar`. O comando:
   - ajusta o banco e os backups para 0600, e `gmax-secrets`, `gmax-backups` e `gmax-retencao` para 0700;
   - confere **na hora** que o banco continua gravável e, se não continuar, desfaz.
4. Recarregue o painel e veja os dois itens "ok" no Diagnóstico.

**Se não for seguro:** se o usuário do site for diferente, não aplique. Abra um chamado na HostGator e pergunte com que usuário o PHP do domínio roda.

A alternativa pelo Gerenciador de Arquivos do cPanel ("Permissões") é possível, mas sem a conferência automática.

## 5. Testes

Todos sem rede e com canal Telegram **falso**: nenhum teste envia mensagem real. A lista completa e os tempos estão em `TESTES.md`.

| Suíte | Cobertura |
|---|---|
| `tests/autolab_test.php` (21) | Cobre os testes obrigatórios que você listou:<br>• destino permitido e não autorizado (público, canal, outro chat, membros demais, bot fora);<br>• canal real recusa outro chat ou texto sem rótulo **sem requisição**;<br>• oferta validada;<br>• preço alterado: usa o preço da API e o catálogo fica intacto;<br>• dados ausentes, link inválido e candidato ML;<br>• simulação;<br>• falhas de rede (Shopee e Telegram);<br>• duplicidade e **6 processos simultâneos → 1 envio**;<br>• **processo morto (SIGKILL) no meio do envio**: vira incerto e não reenvia;<br>• timeout do Telegram, reconciliação, limite diário e intervalo;<br>• pausa emergencial, também no meio da preparação;<br>• fim do período;<br>• painel não tira o AutoLab de "falha" nem de "desativado" (só a CLI);<br>• endereços de site sem `http://` (por exemplo `meli.la/…`, `t.me/…`) são removidos dos títulos e barrados na mensagem;<br>• **isolamento da fila comercial**;<br>• compatibilidade (006 e 005 revertem separadamente);<br>• CLI. |
| `tests/v321_test.php` (13) | Índices em uso, gatilhos de proteção, simulação exata, execução protegida, **retenção interrompida por SIGKILL sem apagar nada**, nenhuma exclusão automática, **revisão morta e retomada no Cron seguinte**, dois Crons simultâneos, `validar-retomada` de ponta a ponta, pasta de validação restrita (nunca a raiz de `gmax-secrets`) e limpeza de cópias antigas, monitor, permissões, SQLite sem WAL |
| `tests/http_test.php` | Páginas AutoLab e Diagnóstico pelo servidor PHP real. Pausa com CSRF. "Falha" não se retoma pelo painel. Destino e duração não editáveis. A exclusão não existe no painel. |
| `tests/ui_test.php` | AutoLab e Diagnóstico nos 9 perfis emulados |
| Demais suítes | Regressão completa |

**Não considere o sistema validado só por estes testes.** Faltam, no servidor:
- a validação real do destino;
- o primeiro envio real;
- `validar-retomada` na HostGator;
- uma semana de AutoLab acompanhada (veja `RELATORIO_HOMOLOGACAO_AUTOLAB.md`).

## 5.1 Revisão independente de segurança

Um revisor independente (agente separado, só leitura) não encontrou falha crítica ou alta. Ele confirmou que o destino é fixo, que nenhuma trava comercial é liberada, que não há envio duplicado e que a retenção não pode apagar nada sozinha. Todos os achados foram corrigidos nesta versão e têm teste:

| Achado | Gravidade | Correção |
|---|---|---|
| O painel conseguia transformar "falha" ou "desativado" em "pausado" e depois retomar sem a CLI | Média | "Pausar" só age sobre "ativo". "Desativado" e "encerrado" apagam o período, e retomar exige nova ativação. O botão de pausa só aparece com o AutoLab ativo |
| Endereços sem `http://` em títulos (`meli.la/…`, `t.me/…`, `www…`) passariam como link clicável | Média | Os títulos perdem qualquer coisa com cara de domínio e as menções `@`. A última barreira recusa qualquer domínio além do link afiliado Shopee permitido |
| A ferramenta de validação aceitava pasta vizinha ou a própria `gmax-secrets` | Baixa | Só aceita uma pasta `gmax-validacao-…` diretamente dentro de `gmax-secrets`, com a cópia como arquivo comum, e fixa a pasta original antes de trocar para a cópia |
| Cópias temporárias abandonadas | Baixa | Cópias com mais de 1 h são removidas, e o modo automático sempre apaga a cópia (`finally`) |
| A conferência depois de ajustar permissões usava a conexão já aberta | Baixa | A conferência abre uma conexão **nova** |
| O Diagnóstico pegava a trava das automações por um instante | Baixa | Não pega mais. A situação vem dos registros "executando" |
| A retenção conferia só a quantidade | Baixa | Também confere o resumo SHA-256 da lista exata (tabela, ID, data) |
| Uma simulação nova a cada clique | Baixa | Pelo painel, uma simulação de menos de 1 h é reaproveitada |
| A leitura da saída dos processos filhos podia travar | Baixa | A saída vai para um arquivo dentro da pasta temporária |

## 6. Benchmarks (ambiente de desenvolvimento, não a HostGator)

Medidos com `tools/benchmark_operacao.php 10000 200000`:
- 10 mil produtos e 100 mil linhas de histórico;
- 200 mil linhas de detalhe de execuções e 50 mil descobertas;
- melhor de 5 execuções.

O resultado é **idêntico com e sem índices** em todas as consultas.

| Operação | Sem os índices da 005 | Com os índices |
|---|---|---|
| Lista de ofertas ordenada | 4,5 ms | 0,6 ms |
| Histórico conferido do Ranking | 43 ms | 16 ms |
| Contagem da simulação de retenção | 23 ms | 11 ms |
| Última execução de cada tarefa | 0,9 ms | 0,08 ms |
| **Gravar 400 linhas de registro** | **724 ms** (uma a uma) | **7,5 ms** (em lote) |

O Ranking completo (cerca de 300 ms com 10 mil produtos) é dominado pelo cálculo em PHP, e os índices não o alteram de forma mensurável. O ganho da gravação em lote tende a ser maior na hospedagem, onde gravar em disco é mais lento.

## 7. Instalação

1. **Backup:** em Sistema, clique em Criar backup agora e compacte a pasta `gmax-auto/`.
2. **Envio:** extraia `GMAX_Ofertas_3.2.1_incremental_arquivos.zip` em `gmax-auto/`. A lista está no `MANIFESTO.txt`.
3. **Migração:** em Sistema, digite `MIGRAR`. Isso aplica a 005 e a 006, com backup automático antes.
4. Recarregue com Ctrl+F5 e confira o rodapé **3.2.1**.
5. **Diagnóstico:** abra a página e confira os itens. Registre o usuário do PHP e siga a seção 4 para as permissões.
6. **Retomada (opcional, recomendado):** `php gmax-automacoes-cli.php validar-retomada` deve devolver `"aprovado": true`. Em hospedagem sem `proc_open`, use `validar-retomada etapa1` e depois `etapa2`.
7. O AutoLab continua **desativado** até a ativação abaixo.

## 8. Ativação inicial do AutoLab (segura)

Pelo Cron temporário ou SSH, com `php gmax-admin-cli.php …`:

1. `autolab status`: deve mostrar o estado desativado.
2. `autolab validar-destino`: somente leitura, **não envia nada**. Confira:
   - `"aprovado": true`;
   - tipo `supergroup`;
   - grupo privado;
   - membros (até 10);
   - bot `administrator` ou `member`.
3. `autolab previa`: mostra a mensagem que seria enviada, **sem enviar**. Confira se o texto está correto.
4. `autolab ativar AUTORIZO_AUTOLAB_HOMOLOGACAO --dias 7`. Esta é a autorização inicial explícita.
5. O **primeiro envio real** acontece no próximo horário (10:00 ou 18:00), pelo Cron que já existe. Depois disso, a operação é automática por 7 dias.
6. **Emergência:**
   - botão **PAUSAR AUTOLAB AGORA** no painel;
   - ou `autolab pausar`;
   - ou crie o arquivo `gmax-secrets/gmax-autolab.pause`.

O Cron precisa estar chamando `gmax-automacoes-cli.php executar-pendentes`, o que o Diagnóstico mostra em "Cron das automações". Se não estiver, o AutoLab não envia: **não altero o Cron**, e incluí-lo exigiria a sua autorização.

## 9. Rollback

- **Só o AutoLab:**
  1. `autolab pausar`;
  2. `php gmax-admin-cli.php reverter --confirmo` **uma vez**, que remove as tabelas da 006 (backup automático antes).
- **Tudo da 3.2.1:**
  1. reverta a 006 como acima;
  2. rode `reverter --confirmo` de novo para a 005, que remove os índices, a tabela de retenção e os gatilhos novos, e devolve o gatilho original;
  3. restaure os arquivos da 3.2.0 com o backup do passo 1 da instalação.
- **Configurações:** as chaves `autolab_*`, `php_web_uid` e `diagnostico_integridade` são ignoradas pela 3.2.0.
- **Retenção:** se uma retenção tiver sido **executada**, as linhas apagadas não voltam com o rollback. Elas ficam no backup `antes-retencao-N` e na exportação em `gmax-secrets/gmax-retencao/`.

## 10. Riscos residuais

1. **Regras dos programas:** o uso de links afiliados Shopee no grupo de testes não pôde ser verificado nos termos do programa. Confirme você.
2. **Envio incerto:** o Telegram não permite ao bot conferir depois se uma mensagem incerta foi publicada. Confira no grupo pela "Ref. AutoLab".
3. **Disponibilidade Shopee:** a API não informa estoque. "Disponibilidade" na mensagem significa "oferta ativa na API".
4. **Destino:** o limite de 10 membros e o grupo privado dependem do que o Telegram informa. Se alguém transformar o grupo em público, o próximo ciclo detecta e para, mas o envio do ciclo anterior já aconteceu.
5. **Benchmarks:** medidos em desenvolvimento, não na HostGator.
6. **Retomada:** o produto que estava sendo consultado no instante exato da queda pode ser consultado de novo, porque o resultado dele não chegou a ser gravado. Os já concluídos não se repetem.
7. **Linhas em lote:** se o servidor matar o processo no meio da revisão, as linhas informativas ("fora da revisão" e "não processado") daquela execução podem não ser gravadas. Isso não afeta a retomada nem os preços.
8. **Permissões:** o comando só aplica quando confirma o mesmo usuário. Sem a extensão `posix` na hospedagem, ele não aplica.

## 11. Critérios de aceite (no servidor)

| # | Critério | Como verificar |
|---|---|---|
| 1 | Rodapé 3.2.1. Migrações 005 e 006 aplicadas. Verificação sem falhas | Sistema |
| 2 | Diagnóstico abre e mostra o Cron com a última chamada recente, o banco em modo `delete` e a integridade ok | Diagnóstico |
| 3 | Permissões corrigidas para 0600/0700, ou explicação de por que não é seguro | `permissoes` e depois `--aplicar` |
| 4 | `validar-retomada` devolve `"aprovado": true` | CLI |
| 5 | Simulação de retenção mostra quantidades e **nada é apagado** | Diagnóstico, Simular retenção |
| 6 | `autolab validar-destino` aprovado e `autolab previa` com texto correto, sem envio | CLI |
| 7 | Depois de `autolab ativar`, a primeira mensagem chega ao grupo no horário, rotulada TESTE ou SIMULAÇÃO | Grupo e página AutoLab |
| 8 | Botão PAUSAR interrompe: nenhum envio no horário seguinte | Painel |
| 9 | Publicação comercial continua bloqueada: envios pausados, aprovação automática desligada, fila sem mudanças | Sistema e Publicações |
| 10 | Uma semana de AutoLab com o relatório diário preenchido e incertos, se houver, conferidos no grupo | `RELATORIO_HOMOLOGACAO_AUTOLAB.md` |
