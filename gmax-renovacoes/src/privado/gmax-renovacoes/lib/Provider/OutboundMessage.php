<?php
declare(strict_types=1);

namespace GmaxRenov\Provider;

final class OutboundMessage
{
    public function __construct(
        public string $to,            // E.164 sem "+"
        public string $body,          // texto completo (prévia/simulação)
        public ?string $templateName, // modelo aprovado na Meta
        public string $language,
        public array $params,         // parâmetros do corpo do modelo, em ordem
        public string $ref            // referência própria, devolvida nos callbacks
    ) {
    }
}
