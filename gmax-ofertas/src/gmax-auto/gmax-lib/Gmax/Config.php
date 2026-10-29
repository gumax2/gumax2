<?php
declare(strict_types=1);

namespace Gmax;

final class Config
{
    /** Único destino Telegram autorizado (supergrupo privado de homologação). */
    public const PRIVATE_CHAT_ID = '-1004443626419';
    /** Frase do arquivo de ativação PRÓPRIO da fila 3.0 (diferente do V2.1 de propósito). */
    public const ACTIVATION_PHRASE = 'AUTORIZO_FILA_GMAX3_PRIVADA';
    public const TZ = 'America/Sao_Paulo';

    public static function secretsDir(): string
    {
        $env = getenv('GMAX_SECRETS_DIR');
        return rtrim(is_string($env) && $env !== '' ? $env : '/home1/gust1922/gmax-secrets', '/');
    }

    /** Em modo de teste, nenhum transporte real (Telegram/HTTP) pode ser criado. */
    public static function testing(): bool
    {
        return getenv('GMAX_TESTING') === '1';
    }

    public static function backupDir(): string
    {
        $secrets = self::secretsDir();
        if (is_dir($secrets) && is_writable($secrets)) {
            return $secrets . '/gmax-backups';
        }
        return GMAX_APP_DIR . '/storage/gmax-backups';
    }

    public static function lockFile(): string
    {
        // Mesmo mutex do publicador V2.1: os dois nunca rodam ao mesmo tempo.
        // Sem alternativa: se a pasta privada não for gravável, o processador não roda.
        return self::secretsDir() . '/gmax-publicador.mutex';
    }

    public static function pauseFile(): string
    {
        return self::secretsDir() . '/gmax-publicacao.pause';
    }

    /** Ativação da fila 3.0. Arquivo distinto do V2.1 para que ligar um não ligue o outro. */
    public static function activationFile(): string
    {
        return self::secretsDir() . '/gmax3-publicacao-privada.php';
    }

    /** Arquivo de ativação do publicador V2.1 (somente leitura: nunca criado pelo 3.0). */
    public static function legacyActivationFile(): string
    {
        return self::secretsDir() . '/gmax-publicacao-privada.php';
    }
}
