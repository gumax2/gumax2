# Limitações e dependências externas

## Ainda não verificado (depende do servidor real)

- **Arquivos:** a cópia sanitizada de 29/10/2026 foi comparada, e os testes usam esses arquivos. Se algo mudar no servidor depois dela, a página Sistema aponta como DIFERENTE.
- **Banco:** a estrutura real não foi vista, porque a cópia não tinha banco. O `gmax-diagnostico-esquema.php` (passo 3) a exporta sem dados, e a verificação confere as colunas obrigatórias antes de migrar.
- O comportamento do Apache da HostGator com o `.htaccess` dentro de `gmax-lib/` precisa do teste manual descrito no passo 4 da instalação.
- A versão do SQLite e a presença da extensão `zip` na HostGator são conferidas pela verificação, mas não puderam ser confirmadas daqui.
- Os dados reais (5 ofertas Shopee, ML-001, registros do V2.1) foram **simulados** nos testes com o mesmo formato. Os IDs e os links Shopee dos testes são fictícios.

## Fora do escopo P0 ou dependentes de terceiros

| Item | Situação |
|---|---|
| Atualização automática de preços da Shopee | Conector pronto, mas inativo até as credenciais da API de afiliados serem liberadas. Endpoint e campos precisam ser conferidos na documentação que vem com o acesso |
| API do Mercado Livre | Conector só de consulta (preço, preço original, situação, variações). Precisa de um token OAuth de aplicativo cadastrado. **Não converte links afiliados**: o link continua vindo do gerador oficial |
| Botão "aplicar dados da API" no produto | Não implementado (P2). Os conectores existem e foram testados com respostas simuladas, mas o painel só mostra a situação deles |
| Comissão, cliques e conversões | Não há fonte oficial integrada. Nada é estimado |
| Histórico de preços | Começa a ser registrado agora. Só as conferências manuais (✓) são tratadas como confiáveis e só entram no ranking com 3 ou mais conferências |
| Potencial por categoria | Precisa ser definido por você (tela Categorias). Sem isso, o critério fica "sem dado" |
| Regras do programa de afiliados do Mercado Livre | Links ML em grupo privado ficam bloqueados por padrão. Liberar exige comando com frase de confirmação, depois de você conferir os termos atuais |
| Reconciliação de pendências do V2.1 | O painel 3.0 mostra as pendências da tabela `gmax_dispatches` e respeita o bloqueio, mas não as edita. Siga o procedimento do V2.1 |
| Restauração completa do banco | Pelo cPanel (ROLLBACK nível C). O painel restaura só as tabelas `gmax3_*` |
| Limite de tentativas de login | Vale no `painel.php`. O `index.php` antigo não tem limite e compartilha a sessão, então o bloqueio pode ser contornado por ele. Não foi corrigido para não alterar arquivos existentes |
| Preço "De" vindo das planilhas de pesquisa | O "Preço referência" das planilhas é gravado como preço original, mas fica marcado como **não conferido**. A publicação exige confirmação **item a item** (não existe confirmação em lote) |
| Usuários múltiplos ou perfis | Existe uma senha de administrador (a mesma do `index.php`), e a auditoria registra "admin" |
| Envio real ao Telegram | **Não testado de propósito.** Os testes usam transporte falso, com `allow_url_fopen=0` e `GMAX_TESTING=1`. A lógica de classificação das respostas do Telegram foi testada com respostas simuladas |
