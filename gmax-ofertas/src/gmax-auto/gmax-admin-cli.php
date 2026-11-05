<?php
declare(strict_types=1);
/*
 * GMAX Ofertas 3.0 — ferramenta de linha de comando (somente CLI).
 * Uso: php gmax-admin-cli.php <comando> [opções]
 *   verificar                 Verificação somente leitura do ambiente (não altera nada)
 *   status                    Resumo do catálogo, fila e travas
 *   backup                    Cria backup verificado do banco
 *   migrar --confirmo         Backup + aplica migrações pendentes
 *   reverter --confirmo       Backup + remove as tabelas gmax3_ (tabelas antigas intactas)
 *   fila:simular              Mostra o que seria publicado; não muda nada e não envia
 *   fila:processar            Processa 1 item; só envia se TODAS as travas liberarem
 *   pausar [motivo]           Pausa emergencial
 *   automacao --autorizar AUTORIZO_AUTOMACAO_COMERCIAL | --desativar
 *   ml-grupo-privado --permitir CONFIRMO_REGRAS_ML_AFILIADOS | --bloquear
 *   publicador-v21 --aposentar CONFIRMO_V21_DESATIVADO | --reativar
 *   importar <arquivo> [--aplicar]  Prévia (e opcionalmente aplicação) de lote
 *   criar-senha               Cria a senha inicial a partir de gmax-secrets/senha-inicial.txt (só se não existir
 *                             usuário; o arquivo é apagado depois). Nunca sobrescreve uma senha existente.
 *   desbloquear-login         Libera o login bloqueado pelo limite de tentativas (registrado na auditoria)
 *   autolab status|validar-destino|previa|ativar AUTORIZO_AUTOLAB_HOMOLOGACAO [--dias 7]|pausar|retomar|desativar
 *                             GMAX AutoLab: homologação automática no Telegram privado (separada da publicação comercial)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Apenas CLI');
}
require __DIR__ . '/gmax-lib/bootstrap.php';

use Gmax\{Backup, Catalog, Db, Importer, Migrator, Preflight, Queue, Settings, TelegramTransport};

function out(array|string $data): void
{
    echo (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) . PHP_EOL;
}

$args = array_slice($argv, 1);
$cmd = $args[0] ?? 'ajuda';
$has = static fn(string $flag) => in_array($flag, $args, true);
$after = static function (string $flag) use ($args): ?string {
    $i = array_search($flag, $args, true);
    return $i === false ? null : ($args[$i + 1] ?? null);
};
$actor = 'cli';

try {
    $pdo = Db::get();
    $needMigrated = static function () use ($pdo): void {
        if (!Migrator::isCurrent($pdo)) {
            throw new RuntimeException('Migração pendente: execute "migrar --confirmo" (ou pelo painel em Sistema)');
        }
    };
    switch ($cmd) {
        case 'criar-senha':
            $f = Gmax\Config::secretsDir() . '/senha-inicial.txt';
            if (is_link($f)) {
                @unlink($f);
                throw new RuntimeException('senha-inicial.txt não pode ser um link simbólico: o link foi apagado. Crie um arquivo comum.');
            }
            if (!is_file($f) || !is_readable($f)) {
                throw new RuntimeException('Crie ' . $f . ' com a senha (de 14 a 72 caracteres) e rode de novo.');
            }
            $loose = (fileperms($f) & 0077) !== 0;
            $pass = preg_replace('/^\xEF\xBB\xBF/', '', rtrim((string)file_get_contents($f), "\r\n"));
            try {
                Gmax\Auth::createInitialPassword($pdo, $pass);
            } finally {
                // a senha em texto nunca fica no servidor, mesmo se houver erro
                if (!@unlink($f) && is_file($f)) {
                    fwrite(STDERR, "ATENÇÃO: não foi possível apagar $f. Apague-o manualmente agora.\n");
                }
            }
            out('Senha inicial criada. Entre pelo painel.php.' . ($loose ? ' Aviso: o arquivo da senha estava legível por outros usuários (permissão diferente de 600); se o servidor for compartilhado, troque a senha.' : ''));
            break;
        case 'autolab':
            $sub = $args[1] ?? 'status';
            $AL = Gmax\AutoLab\AutoLab::class;
            if (!$AL::installed($pdo)) {
                throw new RuntimeException('Migração 006 (AutoLab) pendente: aplique em Sistema → MIGRAR');
            }
            switch ($sub) {
                case 'status':
                    out(['estado' => $AL::state($pdo), 'motivo' => Settings::get($pdo, 'autolab_motivo', ''), 'ate_utc' => Settings::get($pdo, 'autolab_ate', ''),
                        'destino' => Gmax\Config::PRIVATE_CHAT_ID, 'destino_validado_em_utc' => Settings::get($pdo, 'autolab_destino_validado_em', ''),
                        'horarios' => $AL::times($pdo), 'limite_diario' => $AL::dailyLimit($pdo), 'proximo_horario' => $AL::nextSlot($pdo),
                        'arquivo_de_pausa' => is_file($AL::pauseFile()), 'relatorio_7_dias' => $AL::report($pdo, 7)]);
                    break;
                case 'validar-destino':
                    out($AL::validateDestination($pdo, 'cli') + ['observacao' => 'Somente leitura: nenhuma mensagem foi enviada.']);
                    break;
                case 'previa':
                    // Monta a próxima mensagem (consulta a API Shopee só para leitura) e NÃO envia nem grava.
                    $c = Gmax\AutoLab\Composer::compose($pdo, 'previa ' . Gmax\Time::now()->format('Y-m-d H:i'));
                    out(['tipo' => $c['kind'], 'faltando' => $c['missing'], 'verificacoes' => $c['checks'], 'problemas' => $AL::textProblems($c['text'], $c['kind'])]);
                    out("----- mensagem (não enviada) -----\n" . $c['text']);
                    break;
                case 'ativar':
                    $days = (int)($after('--dias') ?? 7);
                    out($AL::activate($pdo, (string)($args[2] ?? ''), $days, 'cli') + ['observacao' => 'O primeiro envio acontece no próximo horário do AutoLab, pelo Cron já existente.']);
                    break;
                case 'pausar':
                    $AL::pause($pdo, trim(implode(' ', array_slice($args, 2))) ?: 'Pausa pela linha de comando', 'cli');
                    out('AutoLab pausado.');
                    break;
                case 'retomar':
                    $AL::resume($pdo, 'cli', true);
                    out('AutoLab ativo de novo.');
                    break;
                case 'desativar':
                    $AL::pause($pdo, 'Desativado pela linha de comando', 'cli', 'desativado');
                    out('AutoLab desativado (para voltar: validar-destino e ativar).');
                    break;
                default:
                    throw new RuntimeException('Uso: autolab status | validar-destino | previa | ativar ' . $AL::ACTIVATION_PHRASE . ' [--dias 7] | pausar [motivo] | retomar | desativar');
            }
            break;
        case 'desbloquear-login':
            // Libera o login bloqueado por tentativas (ex.: ataque que atingiu o limite global de 50/15 min).
            $n = Gmax\Auth::unlock($pdo);
            Gmax\Audit::log($pdo, 'cli', 'login_desbloqueado', 'sessao', null, ['tentativas_apagadas' => $n]);
            out("Login desbloqueado ($n tentativa(s) recente(s) apagada(s)).");
            break;
        case 'verificar':
            $res = Preflight::run($pdo);
            foreach ($res as [$lvl, $item, $detail]) {
                out(sprintf('[%s] %s: %s', strtoupper($lvl), $item, $detail));
            }
            out('MODO: somente leitura — nenhuma alteração e nenhuma mensagem enviada.');
            exit(Preflight::hasFailures($res) ? 1 : 0);
        case 'backup':
            $b = Backup::create($pdo, 'cli');
            out(['backup' => $b['file'], 'sha256' => $b['sha256'], 'bytes' => $b['bytes']]);
            break;
        case 'migrar':
            if (!$has('--confirmo')) {
                out('Pendentes: ' . (implode(', ', Migrator::pending($pdo)) ?: 'nenhuma') . '. Repita com --confirmo para aplicar (backup automático antes).');
                break;
            }
            if (Preflight::hasFailures(Preflight::run($pdo))) {
                throw new RuntimeException('Verificação com FALHA: rode "verificar" e corrija antes de migrar');
            }
            out(['migracoes_aplicadas' => Migrator::up($pdo, $actor)]);
            break;
        case 'reverter':
            if (!$has('--confirmo')) {
                out('Remove as tabelas gmax3_ da última migração (backup automático antes). Repita com --confirmo.');
                break;
            }
            out(['revertido' => Migrator::down($pdo, $actor)]);
            break;
        case 'status':
            $needMigrated();
            out([
                'catalogo' => $pdo->query('SELECT marketplace || ":" || validation_status k, COUNT(*) FROM gmax3_catalog GROUP BY k')->fetchAll(PDO::FETCH_KEY_PAIR),
                'fila' => $pdo->query('SELECT state, COUNT(*) FROM gmax3_pub_queue GROUP BY state')->fetchAll(PDO::FETCH_KEY_PAIR),
                'pausado' => Settings::paused($pdo),
                'travas_envio_real' => Queue::gates($pdo),
            ]);
            break;
        case 'fila:simular':
            $needMigrated();
            out(Queue::processNext($pdo, true, null, $actor));
            break;
        case 'fila:processar':
            $needMigrated();
            out(Queue::processNext($pdo, false, static fn() => TelegramTransport::fromSecrets(), $actor));
            break;
        case 'pausar':
            $needMigrated();
            Settings::pause($pdo, 'Pausa via CLI: ' . ($args[1] ?? 'sem motivo'), $actor);
            out('Publicação pausada.');
            break;
        case 'automacao':
            $needMigrated();
            if ($has('--desativar')) {
                Settings::set($pdo, 'automacao_comercial', '0', $actor);
                out('Automação comercial DESATIVADA.');
            } elseif ($after('--autorizar') === 'AUTORIZO_AUTOMACAO_COMERCIAL') {
                Settings::set($pdo, 'automacao_comercial', '1', $actor);
                out('Automação comercial AUTORIZADA. Envios ainda dependem do arquivo de ativação, da ausência de pausa e de reconciliação em dia.');
            } else {
                throw new RuntimeException('Use --autorizar AUTORIZO_AUTOMACAO_COMERCIAL ou --desativar');
            }
            break;
        case 'ml-grupo-privado':
            $needMigrated();
            if ($has('--bloquear')) {
                Settings::set($pdo, 'ml_grupo_privado_permitido', '0', $actor);
                out('Links Mercado Livre BLOQUEADOS em grupo privado.');
            } elseif ($after('--permitir') === 'CONFIRMO_REGRAS_ML_AFILIADOS') {
                Settings::set($pdo, 'ml_grupo_privado_permitido', '1', $actor);
                out('Links Mercado Livre permitidos em grupo privado (registrado em auditoria).');
            } else {
                throw new RuntimeException('Use --permitir CONFIRMO_REGRAS_ML_AFILIADOS ou --bloquear');
            }
            break;
        case 'publicador-v21':
            $needMigrated();
            if ($has('--reativar')) {
                Settings::set($pdo, 'publicador_v21_aposentado', '0', $actor);
                out('Itens pendentes do publicador antigo voltam a ser bloqueados no 3.0.');
            } elseif ($after('--aposentar') === 'CONFIRMO_V21_DESATIVADO') {
                Settings::set($pdo, 'publicador_v21_aposentado', '1', $actor);
                out('Registrado: o publicador antigo foi desativado e não será mais ativado. Itens Shopee pendentes podem seguir pelo 3.0.');
            } else {
                throw new RuntimeException('Use --aposentar CONFIRMO_V21_DESATIVADO ou --reativar');
            }
            break;
        case 'importar':
            $needMigrated();
            $file = $args[1] ?? '';
            if (!is_file($file)) {
                throw new RuntimeException('Arquivo não encontrado');
            }
            $id = Importer::preview($pdo, $file, basename($file), $actor);
            $batch = Db::one($pdo, 'SELECT * FROM gmax3_import_batches WHERE id=?', [$id]);
            out(['lote' => $id, 'formato' => $batch['format'], 'linhas' => $batch['total'], 'novos' => $batch['inserted'], 'atualizar' => $batch['updated'], 'sem_alteracao' => $batch['unchanged'], 'rejeitados' => $batch['rejected']]);
            foreach (Db::all($pdo, "SELECT line, errors_json FROM gmax3_import_items WHERE batch_id=? AND action='rejeitado'", [$id]) as $r) {
                out("  linha {$r['line']}: " . implode('; ', json_decode((string)$r['errors_json'], true) ?: []));
            }
            if ($has('--aplicar')) {
                out(['aplicado' => Importer::apply($pdo, $id, $actor)]);
            }
            break;
        default:
            out(trim((string)preg_replace('/^\s*\* ?/m', '', explode('*/', explode('/*', (string)file_get_contents(__FILE__), 2)[1])[0])));
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
