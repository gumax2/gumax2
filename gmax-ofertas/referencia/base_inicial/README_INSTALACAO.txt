GMAX OFERTAS — INSTALAÇÃO INDEPENDENTE VIA CPANEL

ATENÇÃO: Este pacote NÃO modifica a VPS /opt/gmax-ofertas, nem seu painel atual.
Não desbloqueia envio do Telegram, não configura WhatsApp e NÃO converte links comuns em afiliados.
É um painel de homologação para trabalhar com CSV oficial Shopee já convertido.

1. No cPanel > Gerenciador de Arquivos, abra public_html.
2. Crie a pasta gmax-auto (não substitua arquivos existentes).
3. Entre nela e faça upload de GMAX_Automacao_CPanel_v1.zip.
4. Clique no ZIP > Extract/Extrair AQUI. Os arquivos podem cair na subpasta gmax_auto_cpanel; nesse caso mova seu conteúdo para public_html/gmax-auto.
5. Confirme que index.php, app.php, cron.php, storage e .htaccess estão em public_html/gmax-auto.
6. Acesse https://SEU-DOMINIO/gmax-auto/ e crie uma senha forte.
7. Na tela do painel, importe BatchProductLinks...csv pela opção Importar.
8. Confira os 5 produtos e copie uma mensagem para revisão.

Requisitos: PHP 8.1+ com PDO SQLite; HTTPS.
A pasta storage tem .htaccess que proíbe acesso web. Não remova.

NÃO CONFIGURE CRON NESTE MOMENTO: arquivo cron.php apenas faz prévia, sem envio.
A execução REAL via Telegram requer autorização de canal privado de teste e implantação posterior segura de tokens fora do acesso público.
Este app é uma homologação separada, não a automação final.
