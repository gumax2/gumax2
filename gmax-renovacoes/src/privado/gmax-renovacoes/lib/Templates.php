<?php
declare(strict_types=1);

namespace GmaxRenov;

/**
 * Montagem das mensagens a partir dos textos configuráveis.
 * Só existem os campos abaixo: senhas, códigos de acesso e URLs de listas NÃO são lidos do banco
 * e não há campo para eles.
 */
final class Templates
{
    public const FIELDS = ['nome', 'pacote', 'data', 'dias', 'valor', 'instrucoes_pagamento', 'ciclo', 'pontos', 'servico', 'lista_pacotes', 'total', 'chave_pix'];

    /**
     * @param array $items linhas normalizadas do PanelData (mesmo cliente, mesmo vencimento)
     * @return array{corpo:string, variante:string, vars:array, parametros:array}
     */
    public static function build(Settings $s, string $stage, array $items): array
    {
        $first = $items[0];
        $variant = count($items) > 1 ? 'grupo' : 'individual';
        $total = array_sum(array_column($items, 'valor_centavos'));
        $stageCfg = null;
        foreach (Stages::all($s) as $st) {
            if ($st['codigo'] === $stage) {
                $stageCfg = $st;
            }
        }
        $lines = [];
        $flat = [];
        foreach ($items as $it) {
            $desc = self::describe($it);
            $lines[] = '• ' . $desc . ' — ' . Format::money($it['valor_centavos']);
            $flat[] = $desc . ' (' . Format::money($it['valor_centavos']) . ')';
        }
        $vars = [
            'nome' => self::firstName($first['cliente_nome']),
            'pacote' => $variant === 'individual' ? self::describe($first) : implode('; ', $flat),
            'data' => Format::date($first['vencimento']),
            'dias' => (string)abs((int)($stageCfg['dias'] ?? 0)),
            'valor' => Format::money($variant === 'individual' ? $first['valor_centavos'] : $total),
            'total' => Format::money($total),
            'ciclo' => (string)($first['ciclo'] ?? ''),
            'pontos' => (string)($first['pontos'] ?? ''),
            'servico' => (string)($first['servico'] ?? ''),
            'lista_pacotes' => implode("\n", $lines),
            'chave_pix' => (string)$s->get('chave_pix'),
        ];
        $vars['instrucoes_pagamento'] = self::replace((string)$s->get('instrucoes_pagamento'), $vars);

        $text = (string)($s->get('textos')[$stage][$variant] ?? '');
        $body = self::replace($text, $vars);
        $footer = trim((string)$s->get('rodape'));
        if ($footer !== '') {
            $body .= "\n\n" . $footer;
        }

        // Parâmetros para o modelo da Meta: uma linha cada, sem quebras (exigência da plataforma).
        $paramKeys = $variant === 'individual'
            ? ['nome', 'pacote', 'data', 'valor', 'instrucoes_pagamento']
            : ['nome', 'data', 'pacote', 'total', 'instrucoes_pagamento'];
        $params = [];
        foreach ($paramKeys as $k) {
            $params[] = self::oneLine($vars[$k]);
        }
        return ['corpo' => $body, 'variante' => $variant, 'vars' => $vars, 'parametros' => $params, 'chaves_parametros' => $paramKeys];
    }

    public static function describe(array $it): string
    {
        $extra = [];
        if (!empty($it['pontos'])) {
            $extra[] = $it['pontos'] . ' ponto' . ($it['pontos'] > 1 ? 's' : '');
        }
        if (!empty($it['ciclo'])) {
            $extra[] = mb_strtolower((string)$it['ciclo']);
        }
        return $it['pacote_nome'] . ($extra ? ' (' . implode(', ', $extra) . ')' : '');
    }

    public static function firstName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'cliente';
        }
        return explode(' ', preg_replace('/\s+/', ' ', $name))[0];
    }

    public static function replace(string $text, array $vars): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', static function ($m) use ($vars) {
            return array_key_exists($m[1], $vars) ? (string)$vars[$m[1]] : $m[0];
        }, $text) ?? $text;
    }

    public static function oneLine(string $s): string
    {
        $s = preg_replace('/[\r\n\t]+/', ' — ', $s) ?? $s;
        $s = preg_replace('/ {4,}/', '   ', $s) ?? $s;
        $s = trim(str_replace('• ', '', $s));
        return mb_substr($s, 0, 1000);
    }

    /**
     * Valida um texto editável. Retorna lista de erros (vazia = ok).
     */
    public static function validate(string $text, bool $isGroup): array
    {
        $errors = [];
        if (trim($text) === '') {
            $errors[] = 'O texto não pode ficar vazio.';
        }
        if (mb_strlen($text) > 1024) {
            $errors[] = 'Texto muito longo (máximo 1024 caracteres, limite do corpo de modelos do WhatsApp).';
        }
        if (preg_match('~https?://|www\.|wa\.me/|\.m3u~i', $text)) {
            $errors[] = 'Não inclua links/URLs nas mensagens.';
        }
        if (preg_match('/\b(senha|password|usu[aá]rio e senha|c[oó]digo de acesso)\b/iu', $text)) {
            $errors[] = 'Não inclua senhas ou códigos de acesso nas mensagens.';
        }
        preg_match_all('/\{([a-z_]+)\}/', $text, $m);
        foreach (array_unique($m[1]) as $f) {
            if (!in_array($f, self::FIELDS, true)) {
                $errors[] = 'Campo desconhecido: {' . $f . '}';
            }
        }
        if ($isGroup && strpos($text, '{lista_pacotes}') === false && strpos($text, '{pacote}') === false) {
            $errors[] = 'A mensagem agrupada precisa de {lista_pacotes} (ou {pacote}) para identificar os pacotes.';
        }
        return $errors;
    }
}
