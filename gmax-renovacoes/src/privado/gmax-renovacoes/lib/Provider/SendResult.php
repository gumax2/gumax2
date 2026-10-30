<?php
declare(strict_types=1);

namespace GmaxRenov\Provider;

/**
 * Resultado de uma tentativa de envio.
 *  aceito      — provedor aceitou e devolveu um ID (NÃO significa entregue)
 *  transitorio — com certeza não foi enviado; pode tentar de novo (limite de taxa, falha de conexão)
 *  permanente  — rejeitado; não tentar de novo (número inválido, modelo inexistente)
 *  incerto     — pode ou não ter sido enviado (timeout depois do envio, resposta 5xx): NÃO reenviar automaticamente
 */
final class SendResult
{
    public const ACCEPTED = 'aceito';
    public const TRANSIENT = 'transitorio';
    public const PERMANENT = 'permanente';
    public const UNCERTAIN = 'incerto';

    public function __construct(
        public string $kind,
        public ?string $providerId = null,
        public ?string $code = null,
        public ?string $detail = null,
        public bool $credentialError = false
    ) {
    }
}
