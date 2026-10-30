<?php
declare(strict_types=1);

namespace GmaxRenov;

/**
 * Estados das mensagens e transições a partir de callbacks (que podem chegar repetidos ou fora de ordem).
 */
final class Statuses
{
    public const LABELS = [
        'aguardando' => 'Aguardando envio',
        'enviando' => 'Enviando…',
        'aceito' => 'Aceito pelo provedor',
        'entregue' => 'Entregue',
        'lido' => 'Lido',
        'falhou' => 'Falhou',
        'cancelado' => 'Cancelado',
        'incerto' => 'Resultado incerto',
        'manual' => 'Enviado manualmente (registro seu)',
    ];

    private const RANK = ['aguardando' => 0, 'enviando' => 1, 'incerto' => 2, 'aceito' => 3, 'entregue' => 4, 'lido' => 5];

    /** Converte o status do webhook da Meta para o nosso vocabulário. */
    public static function fromMeta(string $s): ?string
    {
        return ['sent' => 'aceito', 'delivered' => 'entregue', 'read' => 'lido', 'failed' => 'falhou'][$s] ?? null;
    }

    /**
     * Retorna o novo status ou null se o callback não deve alterar o estado atual.
     * - Nunca regride (ex.: "entregue" chegando depois de "lido" é ignorado).
     * - "falhou" só vale se ainda não houve confirmação de entrega.
     * - Confirmação de entrega/leitura prevalece sobre "falhou" (é evidência mais forte).
     * - Mensagens canceladas ou registradas como manuais não mudam.
     */
    public static function transition(string $current, string $incoming): ?string
    {
        if (in_array($current, ['cancelado', 'manual'], true)) {
            return null;
        }
        if ($incoming === 'falhou') {
            return (self::RANK[$current] ?? 99) < self::RANK['entregue'] ? 'falhou' : null;
        }
        if ($current === 'falhou') {
            return in_array($incoming, ['entregue', 'lido'], true) ? $incoming : null;
        }
        if (!isset(self::RANK[$incoming], self::RANK[$current])) {
            return null;
        }
        return self::RANK[$incoming] > self::RANK[$current] ? $incoming : null;
    }
}
