# GMAX Ofertas 3.0.2 P0: pacote único de atualização

**Situação:** o pacote foi conferido com a cópia dos arquivos atuais da HostGator (29/10/2026). Os testes rodam sobre esses arquivos reais. **Só instale quando você autorizar.** A instalação só **adiciona** arquivos, não ativa nenhuma publicação e não cria nem altera Crons ou credenciais.

## Achado principal

O `gmax-publicador-cli.php` instalado **não é o V2.1**: falta a pausa global depois de um envio incerto. Se ele for ativado um dia, pode reenviar a mesma oferta. Hoje não envia nada, porque está sem ativação. Detalhes e a revisão 2.2 opcional estão em `COMPARATIVO_PRODUCAO.md`.

## Conteúdo

| Arquivo | Para quê |
|---|---|
| `GMAX_Ofertas_3.0.2_P0_arquivos.zip` | **Arquivos a enviar** para `gmax-auto/` (só arquivos novos) |
| `opcional/gmax-publicador-cli.php` | Revisão 2.2 do publicador antigo. **Não instalar agora** (passo 9) |
| `MANIFESTO.txt` | O que é adicionado, modificado (nada) e preservado, com hashes |
| `MIGRACAO_HOSTGATOR.md` | **Roteiro exato para o seu servidor** (diagnóstico de 30/10), com parecer de compatibilidade |
| `INSTALACAO.md` | Backup, envio, diagnóstico, verificação, migração e validação |
| `ROLLBACK.md` | Reversão em níveis |
| `COMPARATIVO_PRODUCAO.md` | Diferenças entre a produção e a 3.0, e os conflitos entre os publicadores |
| `DIAGNOSTICO.md` | Análise técnica e arquitetura |
| `TESTES.md` | Testes **executados**, com resultado gerado automaticamente |
| `LIMITACOES.md` | O que ainda depende do servidor real ou de terceiros |
| `capturas/` | Telas do painel |
| `desenvolvimento/` | Fontes, testes e cópia da produção usada nos testes (não enviar ao servidor) |

## Ordem

1. `INSTALACAO.md`, passos 1 a 5: backup, envio, diagnóstico e verificação. Nada no banco é alterado.
2. Sem **FALHA** na verificação → passo 6 (migrar) e passo 7 (validar).
3. Problema → `ROLLBACK.md`, nível A (1 minuto).
