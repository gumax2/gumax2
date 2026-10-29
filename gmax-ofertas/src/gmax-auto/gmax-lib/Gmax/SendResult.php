<?php
declare(strict_types=1);

namespace Gmax;

/** Resultado de uma tentativa de envio. */
final class SendResult
{
    public const CONFIRMED = 'confirmado';
    public const FAILED = 'falhou';     // o destino recusou de forma explícita: nada foi publicado
    public const UNCERTAIN = 'incerto'; // não há como saber se foi publicado: nunca repetir automaticamente

    public function __construct(
        public readonly string $outcome,
        public readonly int $httpCode = 0,
        public readonly ?int $messageId = null,
        public readonly string $note = '',
    ) {
    }
}
