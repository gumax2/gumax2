<?php
declare(strict_types=1);

namespace Gmax;

use PDO;

/**
 * Mensagens comerciais por modelo (marketplace + categoria, com padrão "*").
 * Texto simples (sem parse_mode): o conteúdo do produto nunca vira formatação no Telegram.
 * Uma linha do modelo que usa um campo sem dado confirmado é REMOVIDA inteira — assim
 * "De", "% OFF" e "Economize" só aparecem quando existe preço original real e maior.
 *
 * Campos: {loja} {titulo} {de} {por} {desconto} {economia} {condicao} {link} {categoria}
 */
final class Message
{
    public const DISCLAIMER = 'Preço e disponibilidade sujeitos a alterações.';
    public const DEFAULT_TEMPLATE = "🛍️ GMAX OFERTAS | {loja}\n\n🔥 {titulo}\n\nDe: {de}\nPor: {por}\n✅ {desconto} OFF\n💰 Economize {economia}\n💳 Condição: {condicao}\n\n🛒 {link}\n\nPreço e disponibilidade sujeitos a alterações.";
    public const FIELDS = ['loja', 'titulo', 'de', 'por', 'desconto', 'economia', 'condicao', 'link', 'categoria'];

    public static function render(array $r, ?PDO $pdo = null): string
    {
        return self::renderWith(self::templateFor($pdo, $r), $r);
    }

    public static function templateFor(?PDO $pdo, array $r): string
    {
        if ($pdo === null || !Db::tableExists($pdo, 'gmax3_templates')) {
            return self::DEFAULT_TEMPLATE;
        }
        $body = Db::value($pdo, "SELECT body FROM gmax3_templates WHERE active=1 AND marketplace IN (?, '*') AND category IN (?, '*')
            ORDER BY (marketplace <> '*') + (category <> '*') DESC, (category <> '*') DESC LIMIT 1", [$r['marketplace'] ?? '', (string)($r['category'] ?? '')]);
        return is_string($body) && trim($body) !== '' ? $body : self::DEFAULT_TEMPLATE;
    }

    public static function renderWith(string $template, array $r): string
    {
        $orig = isset($r['price_original_cents']) ? (int)$r['price_original_cents'] : null;
        $promo = isset($r['price_promo_cents']) ? (int)$r['price_promo_cents'] : null;
        $d = Money::discount($orig, $promo);
        $values = [
            'loja' => mb_strtoupper(Url::MARKETPLACES[$r['marketplace'] ?? ''] ?? 'LOJA', 'UTF-8'),
            'titulo' => trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)($r['title'] ?? ''))),
            'de' => $d ? Money::format($orig) : null,
            'por' => $promo ? Money::format($promo) : null,
            'desconto' => $d && $d['percent'] >= 1 ? $d['percent'] . '%' : null,
            'economia' => $d ? Money::format($d['savings']) : null,
            'condicao' => self::condition($r),
            'link' => !empty($r['affiliate_url']) ? (string)$r['affiliate_url'] : null,
            'categoria' => !empty($r['category']) ? (string)$r['category'] : null,
        ];
        $out = [];
        foreach (preg_split('/\R/u', $template) as $line) {
            $missing = false;
            $line = (string)preg_replace_callback('/\{([a-z]+)\}/', static function ($m) use ($values, &$missing) {
                if (!array_key_exists($m[1], $values)) {
                    return $m[0];
                }
                if ($values[$m[1]] === null || $values[$m[1]] === '') {
                    $missing = true;
                    return '';
                }
                return $values[$m[1]];
            }, $line);
            if (!$missing) {
                $out[] = rtrim($line);
            }
        }
        $text = trim((string)preg_replace("/\n{3,}/", "\n\n", implode("\n", $out)));
        if (!str_contains($text, 'sujeit') && !str_contains($text, 'podem mudar')) {
            $text .= "\n\n" . self::DISCLAIMER;
        }
        return $text;
    }

    /** Erros de um modelo (campo desconhecido, sem link, sem preço). @return list<string> */
    public static function validateTemplate(string $body): array
    {
        $e = [];
        preg_match_all('/\{([a-z]+)\}/', $body, $m);
        foreach (array_unique($m[1]) as $f) {
            if (!in_array($f, self::FIELDS, true)) {
                $e[] = "Campo desconhecido: {{$f}}";
            }
        }
        foreach (['link', 'por', 'titulo'] as $req) {
            if (!in_array($req, $m[1], true)) {
                $e[] = "O modelo precisa conter {{$req}}";
            }
        }
        if (mb_strlen($body) > 1500) {
            $e[] = 'Modelo muito longo (máximo 1500 caracteres)';
        }
        return $e;
    }

    public static function condition(array $r): ?string
    {
        $note = trim((string)($r['payment_note'] ?? ''));
        $base = match ($r['payment_condition'] ?? null) {
            'pix' => 'pagamento via Pix',
            'cupom' => 'preço com cupom',
            'cartao' => 'preço no cartão',
            default => null,
        };
        if ($base && $note !== '') {
            return "$base ($note)";
        }
        if (($r['payment_condition'] ?? null) === 'outro' && $note !== '') {
            return $note;
        }
        return $base;
    }
}
