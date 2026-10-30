# O que realmente resta (situação em 30/10/2026)

## Já resolvido nesta etapa
- Base do painel recebida e integrada. A tabela abaixo mostra o que a base trouxe e como o módulo usa cada item.

| Item da base | Como o módulo trata |
|---|---|
| Fonte dos avisos | `client_plans.due_date` (o `clients.due_date` legado é ignorado) |
| Excluídos | `client_exclusions` |
| Valores | `price_cents` (total do ciclo) |
| Ciclo | `months` 1/3/6/12 |
| IDs | texto |
| Login | sessão do painel reaproveitada; módulo **sem credencial própria**, lê o config privado do painel |
| Menu | link inserido com conferência, cópia e reversão |

- 89 testes executados em bancos **descartáveis**, incluindo o `api.php` real: login, CSRF, logout, expiração, renovação por `entry`, `delete_client` e regressão do `action=data`. Resultado em `TESTES_RESULTADO.md`.
- Duas rodadas de revisão de segurança, sem achado de gravidade alta. Correções aplicadas e cobertas por testes (`REVISAO_SEGURANCA.md`).

## Para INSTALAR (depende de você)
1. **Revisão do pacote** (Codex/você): ZIP `dist/GMAX_Renovacoes_1.1.0_incremental.zip` e a branch `claude/gmax-whatsapp-renewal-reminders-poplk0`.
2. **Backup SQL completo** do banco `gust1922_gmaxpainel` + ZIP da pasta `painel`, guardados fora do servidor e fora do Git (`BACKUP_E_RECUPERACAO.md`, parte A).
3. **Conferir no servidor** que o caminho do config privado do painel é o mesmo do `api.php` (`/home1/gust1922/gmax-painel/private/config.php`) e que o PHP da linha de comando é 8.0+.
4. Seguir `INSTALACAO.md`. O passo 5 (comparativo de integridade antes/depois) é a **prova na produção**. Até lá, a preservação só foi demonstrada em banco descartável, com a estrutura da base de 15/10, que pode diferir da produção.
5. Aplicar o link do menu com `menu verificar` e depois `menu aplicar`. Se o `index.html` de produção estiver diferente da base, o comando não altera nada; nesse caso me envie o arquivo.

## Para ATIVAR a simulação
6. Revisar os textos na aba Configurações e marcar **Módulo ativo** (Modo continua **Simulação**). O simulado não envia nada e **não prova entrega**.
7. Registrar autorizações **reais** na aba Autorizações. Importar a carteira não autorizou ninguém.

## Para o ENVIO REAL (etapa futura, só com sua autorização expressa)
8. Escolher o caminho do número (`WHATSAPP_API.md` §2):
   - coexistência via parceiro (mantém o número e o app);
   - ou número novo direto na Cloud API.
   Se for um parceiro com endpoint próprio, é preciso escrever o conector dele.
9. Conta Meta Business + WABA + forma de pagamento. Conferir a **tabela oficial de preços** (BRL).
10. Credenciais no `config.php` do módulo, **somente no servidor**:
    - `phone_number_id`
    - `waba_id`
    - token de Usuário do Sistema
    - App Secret
    - verify token
11. Cadastrar e aprovar os **6 modelos** (com a linha de descadastro no texto aprovado). Depois, preencher os nomes na aba.
12. Configurar o webhook `/painel/renovacoes-webhook.php`, campo `messages`. Se a pasta `/painel/` tiver senha HTTP no `.htaccess`, avise: não altero o `.htaccess`.
13. Confirmar a chave Pix `gumaxskins@gmail.com` e as instruções na aba.
14. Modo Real com **"somente números de teste"** marcado → mensagem de teste para um contato **interno** → conferir *Aceito → Entregue → Lido* no Histórico.
15. Só então desmarcar "somente números de teste".

## Limitações conhecidas (não bloqueiam)
- A base é a entrega de 15/10, não um download da produção. Diferenças na produção aparecem no `diagnostico`, no `menu verificar` e no comparativo de integridade. Em nenhum caso o módulo grava por cima.
- "Somente leitura" é garantido pelo código, não pelo banco: o usuário MySQL do painel pode gravar em tudo, e a hospedagem não permite permissão por tabela.
- Envio com resposta perdida fica **Resultado incerto** até a reconciliação. Reenviar, enviar manualmente ou mandar mensagem de teste são decisões suas e podem duplicar.
