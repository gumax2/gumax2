<?php
declare(strict_types=1);

namespace GmaxRenov;

/**
 * Log em arquivo, fora da pasta pública. Nunca grave tokens: tudo passa por redact().
 */
final class Log
{
    private string $dir;
    private Clock $clock;

    public function __construct(string $dir, Clock $clock)
    {
        $this->dir = rtrim($dir, '/');
        $this->clock = $clock;
    }

    public function info(string $msg, array $ctx = []): void
    {
        $this->write('INFO', $msg, $ctx);
    }

    public function error(string $msg, array $ctx = []): void
    {
        $this->write('ERRO', $msg, $ctx);
    }

    public static function redact(string $s): string
    {
        // Tokens de acesso da Meta (EAA...), cabeçalhos Bearer e parâmetros access_token.
        $s = preg_replace('/EAA[A-Za-z0-9]{10,}/', '[TOKEN-OCULTO]', $s) ?? $s;
        $s = preg_replace('/(Bearer\s+)[A-Za-z0-9._\-]+/i', '$1[OCULTO]', $s) ?? $s;
        $s = preg_replace('/(access_token=)[^&\s"]+/i', '$1[OCULTO]', $s) ?? $s;
        $s = preg_replace('/("?(?:app_secret|access_token|verify_token)"?\s*[:=]\s*"?)[^",\s}]+/i', '$1[OCULTO]', $s) ?? $s;
        return $s;
    }

    private function write(string $level, string $msg, array $ctx): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0750, true);
        }
        $line = sprintf(
            "[%s] %s %s%s\n",
            $this->clock->now()->format('Y-m-d H:i:s'),
            $level,
            $msg,
            $ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        @file_put_contents($this->dir . '/renovacoes-' . $this->clock->now()->format('Y-m') . '.log', self::redact($line), FILE_APPEND | LOCK_EX);
    }
}
