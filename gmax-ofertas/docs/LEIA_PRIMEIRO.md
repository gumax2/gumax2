# GMAX Ofertas 3.0 P0: pacote único de atualização

**Situação: PRÉVIA AINDA NÃO LIBERADA PARA INSTALAÇÃO.** O código foi desenvolvido e testado com as versões de referência do kit, porque os arquivos atuais da HostGator não estavam disponíveis. A instalação só **adiciona** arquivos. Antes de migrar, a página Sistema compara o servidor real com as referências.

Nenhuma publicação, nenhum Cron e nenhuma credencial foram criados ou alterados.

## Conteúdo

| Arquivo | Para quê |
|---|---|
| `GMAX_Ofertas_3.0_P0_arquivos.zip` | **Arquivos a enviar** para `gmax-auto/` (só arquivos novos) |
| `MANIFESTO.txt` | Lista do que é adicionado, modificado (nada) e preservado, com hashes |
| `INSTALACAO.md` | Passo a passo pelo cPanel: backup, envio, verificação, migração e validação |
| `ROLLBACK.md` | Três níveis de reversão |
| `DIAGNOSTICO.md` | Análise técnica da base atual, riscos e arquitetura |
| `TESTES.md` | Testes **executados**, com resultado gerado automaticamente |
| `LIMITACOES.md` | O que ainda depende do servidor real ou de terceiros |
| `capturas/` | Telas do painel no computador e no celular |
| `desenvolvimento/` | Código-fonte, testes, arquivos de referência e script de build (não enviar ao servidor) |

## Ordem de leitura

1. `INSTALACAO.md`, passos 1 a 5 (backup e verificação, sem alterar nada).
2. Se a verificação não mostrar **FALHA**, siga para o passo 6 (migrar).
3. Em caso de dúvida, consulte `ROLLBACK.md`. O nível A desfaz tudo em 1 minuto.
