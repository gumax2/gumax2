# O que ainda depende de você

## Bloqueia a instalação no servidor

1. **Arquivos do painel** (sem `config.php`, senhas, tokens ou banco de clientes). Eles não estavam anexados nem no repositório. Preciso de:
   - a estrutura das tabelas de **clientes** e **pacotes** (phpMyAdmin → tabela → Estrutura, ou só um `SHOW CREATE TABLE` sem dados) — para fechar o mapeamento `esquema` no `config.php`;
   - o arquivo de **login** e o que verifica a sessão — para preencher `auth.chave_sessao` (e `sessao_nome`/`csrf_chave_sessao`, se houver);
   - o arquivo do **menu/abas** — para adicionar o link "Renovações" sem mexer no resto;
   - como funcionam **status** e **lixeira** de clientes e pacotes (valores usados para ativo, pausado, arquivado, excluído).
   Sem isso, rode `php bin/renovacoes-admin.php diagnostico` no servidor e me mande a saída (ela só lista tabelas e colunas, sem dados).
2. **Confirmar o Pix:** a chave padrão é `gumaxskins@gmail.com` (tipo e-mail) e as instruções "Pix (chave e-mail): …". Confira e confirme na aba Configurações. Sem a confirmação, o envio real fica bloqueado.

## Bloqueia o envio real (a simulação funciona sem isso)

3. **Escolher o caminho do número** (ver `WHATSAPP_API.md` §2): coexistência via BSP (mantém o número atual e o app) **ou** número novo direto na Cloud API. Se escolher um BSP com endpoint próprio, me diga qual, para eu escrever o conector dele.
4. **Conta Meta Business + WABA + forma de pagamento** e as **credenciais** (você mesmo coloca no `config.php` do servidor; não me envie): `phone_number_id`, `waba_id`, token de Usuário do Sistema, App Secret, verify token.
5. **Cadastrar e aprovar os 6 modelos** (`WHATSAPP_API.md` §4) e preencher os nomes na aba.
6. **Webhook** na Meta apontando para `/painel/renovacoes-webhook.php`. Confirmar se `/painel/` tem senha HTTP no `.htaccess` (se tiver, o webhook precisa ficar em outra pasta; não vou alterar o `.htaccess`).
7. **Autorização dos clientes:** registrar na aba Autorizações quem concordou em receber lembretes.
8. **Número interno de teste** para o primeiro envio real.

## Decisões com padrão já aplicado (mude se quiser)

- Horário 09:00, janela até 20:00, cron a cada 15 min, 3 tentativas (5/10/20 min).
- Lembrete atrasado não é enviado no dia seguinte (expira).
- Rodapé "Para não receber mais estes lembretes, responda SAIR." (ajuda na conformidade; editável).
- Autorização vinculada ao telefone (se o telefone mudar, precisa registrar de novo).
- Janela de exibição da aba: vencidos dos últimos 15 dias e próximos 7 dias.
