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
                out('Itens pendentes do V2.1 voltam a ser bloqueados no 3.0.');
            } elseif ($after('--aposentar') === 'CONFIRMO_V21_DESATIVADO') {
                Settings::set($pdo, 'publicador_v21_aposentado', '1', $actor);
                out('Registrado: o publicador V2.1 foi desativado e não será mais ativado. Itens Shopee pendentes podem seguir pelo 3.0.');
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
