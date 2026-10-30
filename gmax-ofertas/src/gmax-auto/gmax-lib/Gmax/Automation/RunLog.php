<?php
declare(strict_types=1);

namespace Gmax\Automation;

use Gmax\Db;
use Gmax\Time;
use PDO;

/**
 * Detalhamento por produto de cada execução (tabela da migração 003, somente-inclusão).
 * Sem a migração 003 nada é gravado e as tarefas funcionam como na 3.1.1.
 * Nunca grava tokens: mensagens de erro passam por sanitize().
 */
final class RunLog
{
    public const OUTCOMES = [
        'atualizado' => 'Atualizado pela API',
        'validado' => 'Validado (preço confirmado, sem mudança)',
        'sem_alteracao' => 'Consultado, sem alteração e sem validação',
        'bloqueado' => 'Bloqueado (não foi possível consultar)',
        'nao_processado' => 'Elegível, não processado',
        'fora_da_revisao' => 'Fora da revisão',
    ];

    public static function enabled(PDO $pdo): bool
    {
        return Db::tableExists($pdo, 'gmax3_job_run_items');
    }

    /** Remove qualquer coisa parecida com credencial antes de gravar ou exibir. */
    public static function sanitize(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = preg_replace('/\b(Bearer|Basic)\s+\S+/i', '$1 [oculto]', $text);
        $text = preg_replace('/\b(access_token|refresh_token|client_secret|code_verifier|code|token|secret|signature)=([^&\s]+)/i', '$1=[oculto]', (string)$text);
        $text = preg_replace('/\b(APP_USR|TG)-[A-Za-z0-9-]{8,}/', '$1-[oculto]', (string)$text);
        $text = preg_replace('/\bSignature=[0-9a-f]{16,}/i', 'Signature=[oculto]', (string)$text);
        return mb_substr((string)$text, 0, 300);
    }

    /** @param array<string,mixed> $f */
    public static function add(PDO $pdo, int $runId, array $r, string $outcome, string $reason, array $f = []): void
    {
        if ($runId <= 0 || !self::enabled($pdo)) {
            return;
        }
        $int = static fn($v) => $v === null || $v === '' ? null : (int)$v;
        Db::run($pdo, 'INSERT INTO gmax3_job_run_items(run_id,catalog_id,marketplace,external_id,title,outcome,reason,api_endpoint,http_status,api_error,
            price_before_cents,price_api_cents,original_before_cents,original_api_cents,availability_api,checked_at,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            $runId, $int($r['id'] ?? null), (string)($r['marketplace'] ?? '?'), $r['external_id'] ?? null, mb_substr((string)($r['title'] ?? ''), 0, 300),
            $outcome, self::sanitize($reason), $f['endpoint'] ?? null, $int($f['http'] ?? null), self::sanitize($f['error'] ?? null),
            $int($r['price_promo_cents'] ?? null), $int($f['price_api'] ?? null), $int($r['price_original_cents'] ?? null), $int($f['original_api'] ?? null),
            $f['availability'] ?? null, $f['checked_at'] ?? null, Time::nowSql(),
        ]);
    }
}
