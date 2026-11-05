# Pergunta técnica para o suporte da HostGator: SQLite e modo WAL

Este texto está pronto para enviar ao suporte (chat ou chamado). O GMAX Ofertas **continua no modo atual do SQLite** (journal `DELETE`, sem WAL) até a resposta chegar. Nenhuma correção da 3.2.0 depende dela.

---

**Assunto:** Armazenamento e travas de arquivo para banco SQLite em modo WAL (conta gust1922)

Olá. Uso um banco SQLite (PHP 8.3, extensão PDO SQLite) em `/home1/gust1922/gmax-secrets/`, fora da pasta pública. O banco é acessado ao mesmo tempo pelo PHP do site (Apache/PHP-FPM) e por tarefas do Cron (PHP CLI). Antes de ativar o modo WAL (`PRAGMA journal_mode=WAL`), preciso confirmar:

1. O diretório `/home1/gust1922/` fica em **disco local** do servidor ou em **armazenamento de rede** (NFS, CephFS, GlusterFS ou similar)? O SQLite em WAL exige memória compartilhada (arquivo `-shm` via `mmap`) e não é suportado em sistemas de arquivos de rede.
2. As travas de arquivo POSIX (`fcntl`) e `flock()` funcionam de forma confiável e são **compartilhadas entre o PHP do site e o PHP do Cron** da mesma conta? Os dois rodam no mesmo servidor físico/contêiner?
3. Há alguma restrição a `mmap()` ou a arquivos de memória compartilhada (`banco.sqlite-shm`) para contas de hospedagem compartilhada (CloudLinux/LVE, CageFS)?
4. O PHP do site e o PHP do Cron usam **a mesma versão** da biblioteca SQLite? Qual (`SELECT sqlite_version()`)?
5. Os backups automáticos da conta copiam o banco de forma consistente com os arquivos `-wal` e `-shm` presentes, ou copiam só o arquivo principal? (Em WAL, copiar só o `.sqlite` pode gerar um backup incompleto.)
6. Há limites de I/O ou de arquivos abertos simultâneos (LVE: IOPS, inodes, processos) que eu deva considerar para até 2 processos PHP simultâneos gravando no mesmo banco?

Obrigado.

---

## Como interpretar a resposta

| Resposta | Decisão |
|---|---|
| Disco local, `fcntl`/`flock` confiáveis e compartilhados, `mmap` permitido | WAL pode ser avaliado numa versão futura, com teste de concorrência no próprio servidor e backup por `VACUUM INTO` (já usado pelo painel, que gera cópia consistente mesmo em WAL) |
| Armazenamento de rede, ou travas/`mmap` não garantidos | **Manter o modo atual** (journal `DELETE`), que é o seguro nesse cenário |
| Sem resposta clara | Manter o modo atual |
