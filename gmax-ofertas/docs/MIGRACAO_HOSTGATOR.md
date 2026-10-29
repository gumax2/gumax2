# Roteiro de migração: HostGator (diagnóstico de 30/10/2026)

Este roteiro substitui os passos 5 a 7 de `INSTALACAO.md` para o seu servidor. Ele parte do diagnóstico que você executou.

## 1. Parecer de compatibilidade

| Requisito da 3.0.2 | Servidor | Situação |
|---|---|---|
| PHP 8.1 ou mais | 8.3 | ✅ |
| SQLite 3.27 ou mais (backup `VACUUM INTO`); 3.24 para upsert | 3.34.1 | ✅ |
| pdo_sqlite, mbstring, json | presentes | ✅ |
| zip, dom, simplexml (importar .xlsx) | presentes | ✅ |
| Integridade do banco | `quick_check=ok` | ✅ |
| `offers` (5), `gmax_ml_offers` (1), `users` (1) | conferem | ✅ Serão copiados: `{"shopee":5,"mercadolivre":1}` |
| `slots` vazia | 0 registros | ✅ Nenhuma pendência do cron antigo |
| `gmax_dispatches` | **não existe** | ✅ Sem problema (ver seção 2) |
| `gmax3-publicacao-privada.php` | ausente | ✅ Fila 3.0 sem ativação |
| `gmax-publicacao-privada.php` | **EXISTE** | ⚠️ **Neutralizar antes de migrar** (seção 3) |
| `gmax-publicacao.pause` | ausente | ⚠️ Criar (seção 3) |

**Conclusão: a migração é compatível**, desde que a ativação do publicador antigo seja neutralizada antes. Na versão 3.0.2, a verificação do painel mostra **FALHA** e **impede a migração** enquanto a ativação antiga existir sem o arquivo de pausa.

## 2. A ausência de `gmax_dispatches` causa problema?

**Não.** Testado no cenário exato do seu servidor: 5 ofertas pendentes, sem `gmax_dispatches` e `slots` vazia.

- Toda leitura dessa tabela na 3.0 verifica antes se ela existe. A verificação, a fila, as regras de duplicidade e a tela Publicações funcionam sem ela.
- A migração **não cria** `gmax_dispatches`. Quem cria é o publicador antigo, e só quando consegue passar da trava de ativação.
- Por que ela não existe: o publicador antigo nunca chegou à etapa de envio **neste banco**. Ou a ativação nunca estava válida num horário fixo, ou o Cron não rodou nesses horários.

**Consequência importante:** o "envio real de homologação já confirmado" **não está registrado neste banco**. As 5 ofertas estão `pendente`, e `slots` e `gmax_dispatches` estão vazias. A 3.0 não tem como saber qual produto já foi ao grupo. Quando um dia for publicar, confira no grupo de homologação qual oferta já saiu e **arquive** esse produto no painel, ou simplesmente não o coloque na fila.

## 3. Ponto de segurança: a ativação antiga existe

Se o conteúdo de `gmax-publicacao-privada.php` estiver válido e o `telegram.php` também, o publicador antigo **envia** a primeira oferta pendente no próximo horário fixo (08h, 10h, 12h, 15h, 18h ou 20h) em que o Cron rodar. Desativar o Cron resolve só enquanto ele estiver desativado. Faça as **duas** proteções abaixo, reversíveis e sem abrir o arquivo:

1. Em `gmax-secrets/`, crie um arquivo vazio chamado **`gmax-publicacao.pause`** (no cPanel: botão **+ Arquivo**). O publicador antigo verifica esse arquivo antes de enviar e **para**. A 3.0 também o respeita.
2. Renomeie `gmax-publicacao-privada.php` para **`gmax-publicacao-privada.php.desativado-AAAAMMDD`**. Não apague: para voltar ao estado anterior basta renomear de volta.

## 4. Passos exatos da migração

### A. Preparação (sem tocar no banco)
1. **Cron Jobs:** desative (ou comente com `#`) o Cron do publicador antigo. **Anote a linha exata** para restaurar depois.
2. Faça a seção 3: crie `gmax-publicacao.pause` e renomeie a ativação.
3. **Backup completo:** Gerenciador de Arquivos → botão direito em `gmax-auto` → **Compress** → `gmax-auto-backup-AAAAMMDD.zip` → mova para `gmax-secrets/`.
4. **Cópia extra do banco:** copie `gmax-auto/storage/offers.sqlite` para `gmax-secrets/offers-antes-3.0-AAAAMMDD.sqlite`. Confira se os dois arquivos têm o mesmo tamanho.

### B. Atualizar para a 3.0.2
O `painel.php` que o diagnóstico encontrou é o da 3.0.1 que você já enviou.

5. Envie `GMAX_Ofertas_3.0.2_P0_arquivos.zip` para `gmax-auto/` → **Extract**. Isso sobrescreve **somente** os arquivos da própria 3.0 (`painel.php`, `gmax-admin-cli.php`, `gmax-diagnostico-esquema.php`, `gmax-lib/`, `gmax-assets/`).
6. Apague o ZIP enviado.
7. Faça o teste de proteção web (passo 4 de `INSTALACAO.md`): `…/gmax-lib/reference-hashes.json` deve dar **403**.

### C. Verificação no painel
8. Abra `https://gmaxcanais.com.br/gmax-auto/painel.php` e entre. O rodapé deve mostrar **GMAX Ofertas 3.0.2-p0**.
9. **Sistema → Verificação do ambiente:**
   - **nenhuma FALHA**;
   - `Ativação do publicador antigo`: **ausente (bloqueado)** se você renomeou, ou **AVISO** "…gmax-publicacao.pause está presente…";
   - os 6 arquivos antigos: **idêntico à cópia de produção de 29/10/2026**;
   - `Tabela offers: 5 registro(s)`, `Tabela gmax_ml_offers: 1 registro(s)`, `Tabela users: 1 registro(s)`;
   - `Pendências de reconciliação de envios: nenhuma`;
   - `Versão do SQLite: 3.34.1`.

   Se qualquer item divergir, **pare** e me mande um print da tabela.

### D. Migrar
10. Em **Sistema**, digite `MIGRAR` → **Fazer backup e migrar**.
11. A mensagem esperada é: *"Migração aplicada. Backup prévio: gmax-…-antes-migracao-001.sqlite. Produtos copiados: {"shopee":5,"mercadolivre":1}"*.
12. O sistema verificou esse backup (`PRAGMA integrity_check`) **antes** de migrar. Ele está em `gmax-secrets/gmax-backups/`.

### E. Confirmação de dados preservados
13. **Visão geral:** Shopee **5**, Mercado Livre **1**.
14. `index.php`: as 5 ofertas Shopee, todas como antes. `mercadolivre.php`: a parafusadeira ML-001.
15. **Ofertas:** abra cada um dos 5 produtos Shopee e compare o **link afiliado** com o do `index.php`. Devem ser idênticos.
16. **ML-001:** De R$ 299,90 / Por R$ 104,31 / 65% OFF / link `https://meli.la/1uPYV76`.
17. **Publicações:** "Pausado" e "Automação comercial desativada". Tabela da fila: vazia.
18. **Sistema → Verificação de novo:** `Migrações: banco atualizado`.

### F. Depois da migração
19. Deixe a ativação antiga **renomeada** e o arquivo `gmax-publicacao.pause` **no lugar**.
20. O Cron do publicador antigo pode voltar (com a linha anotada): sem ativação, ele só registra `simulacao_sem_envio`. Se preferir, deixe-o desativado até decidir qual publicador vai usar.

## 5. Rollback

| Situação | Ação |
|---|---|
| Painel com problema, banco ok | **Nível A:** apague `painel.php`, `gmax-admin-cli.php`, `gmax-diagnostico-esquema.php`, `gmax-lib/` e `gmax-assets/` (ou renomeie com `.off`). O sistema antigo segue igual |
| Quero tirar as tabelas novas | **Nível B:** Cron temporário `…/gmax-admin-cli.php reverter --confirmo > …/gmax-secrets/gmax-reverter.log 2>&1`, uma vez, e depois remova o Cron. Remove só as tabelas `gmax3_*`, com backup antes |
| Banco danificado | **Nível C:** renomeie `storage/offers.sqlite` para `offers.sqlite.falha` e copie `gmax-secrets/offers-antes-3.0-AAAAMMDD.sqlite` (passo 4) ou o backup de `gmax-backups/` para `storage/offers.sqlite`, com permissão `0600` |
| Desfazer a proteção da seção 3 (somente se você decidir) | Renomeie `gmax-publicacao-privada.php.desativado-AAAAMMDD` de volta e apague `gmax-publicacao.pause`. **Atenção:** isso pode **reativar envios** do publicador antigo |

Nenhum passo deste roteiro ativa publicações. Ativar envios continua exigindo sua decisão explícita (item 10 de `INSTALACAO.md`).
